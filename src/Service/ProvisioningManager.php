<?php

namespace Drupal\traccar_provisioning\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\traccar_provisioning\Exception\ProvisioningException;
use Psr\Log\LoggerInterface;

/**
 * Coordinates Traccar device creation and client configuration generation.
 */
class ProvisioningManager {

  use StringTranslationTrait;

  /**
   * The Traccar API client.
   */
  protected TraccarApiClient $apiClient;

  /**
   * The QR code generator.
   */
  protected QrCodeGenerator $qrGenerator;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * Constructs a new ProvisioningManager.
   */
  public function __construct(TraccarApiClient $api_client, QrCodeGenerator $qr_generator, ConfigFactoryInterface $config_factory, LoggerInterface $logger, TimeInterface $time) {
    $this->apiClient = $api_client;
    $this->qrGenerator = $qr_generator;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->time = $time;
  }

  /**
   * Returns the groups configured on the Traccar server.
   *
   * @return array<int, string>
   *   Group options keyed by group ID.
   */
  public function getGroups(): array {
    return $this->apiClient->getGroups();
  }

  /**
   * Checks whether the module has enough configuration to work.
   */
  public function isConfigured(): bool {
    $config = $this->configFactory->get('traccar_provisioning.settings');
    if (trim((string) $config->get('api_url')) === '' || trim((string) $config->get('client_url')) === '') {
      return FALSE;
    }

    if ((string) $config->get('auth_method') === 'basic') {
      return trim((string) $config->get('username')) !== '' && (string) $config->get('password') !== '';
    }

    return trim((string) $config->get('api_token')) !== '';
  }

  /**
   * Validates a Traccar device identifier.
   *
   * @param string $identifier
   *   The identifier to validate.
   *
   * @return string|null
   *   An error message, or NULL when the identifier is valid.
   */
  public function validateIdentifier(string $identifier): ?string {
    $identifier = trim($identifier);

    if ($identifier === '') {
      return (string) $this->t("L'identifiant est obligatoire.");
    }

    if (mb_strlen($identifier) > 128) {
      return (string) $this->t("L'identifiant ne peut pas dépasser 128 caractères.");
    }

    if (preg_match('#[/\\\\]#', $identifier)) {
      return (string) $this->t("L'identifiant ne peut pas contenir de barre oblique.");
    }

    if ($identifier === '.' || $identifier === '..') {
      return (string) $this->t("L'identifiant ne peut pas être « . » ou « .. ».");
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $identifier)) {
      return (string) $this->t("L'identifiant contient des caractères non autorisés.");
    }

    $pattern = trim((string) $this->configFactory->get('traccar_provisioning.settings')->get('identifier_pattern'));
    if ($pattern !== '') {
      $result = @preg_match($pattern, $identifier);
      if ($result === FALSE) {
        return (string) $this->t("Le format d'identifiant configuré est invalide. Contactez un administrateur.");
      }
      if ($result === 0) {
        return (string) $this->t("L'identifiant ne respecte pas le format autorisé.");
      }
    }

    return NULL;
  }

  /**
   * Creates a Traccar device and generates its client configuration.
   *
   * @param string $identifier
   *   The device unique identifier.
   * @param string $name
   *   The device display name. Defaults to the identifier.
   * @param int $group_id
   *   The Traccar group ID, or 0 for no group.
   *
   * @return array
   *   Provisioning result data.
   *
   * @throws \Drupal\traccar_provisioning\Exception\ProvisioningException
   *   When the request is invalid.
   * @throws \Drupal\traccar_provisioning\Exception\TraccarApiException
   *   When the Traccar API request fails.
   */
  public function createProvisioning(string $identifier, string $name, int $group_id = 0): array {
    $identifier = trim($identifier);
    $name = trim($name);

    $error = $this->validateIdentifier($identifier);
    if ($error !== NULL) {
      throw new ProvisioningException($error);
    }

    if ($name === '') {
      $name = $identifier;
    }
    if (mb_strlen($name) > 255) {
      throw new ProvisioningException('Le nom ne peut pas dépasser 255 caractères.');
    }

    $config = $this->configFactory->get('traccar_provisioning.settings');
    $groups = $this->getGroups();

    if ($group_id > 0 && !isset($groups[$group_id])) {
      throw new ProvisioningException("Le groupe sélectionné n'existe plus sur le serveur Traccar.");
    }

    if ($group_id <= 0 && (bool) $config->get('group_required')) {
      throw new ProvisioningException('Vous devez sélectionner un groupe.');
    }

    $existing = $this->apiClient->findDeviceByUniqueId($identifier);
    if ($existing !== NULL) {
      throw new ProvisioningException(sprintf("Un appareil avec l'identifiant %s existe déjà sur le serveur Traccar.", $identifier));
    }

    // Build the client configuration before creating the remote device so a
    // local configuration or QR generation error cannot leave an orphan device.
    $config_url = $this->buildConfigUrl($identifier);
    $qr_svg = $this->qrGenerator->generateSvg($config_url);

    $device = $this->apiClient->createDevice($identifier, $name, $group_id);

    $result = [
      'identifier' => $identifier,
      'name' => $name,
      'device_id' => (int) $device['id'],
      'group_id' => $group_id,
      'group_name' => $groups[$group_id] ?? (string) $this->t('Aucun groupe'),
      'config_url' => $config_url,
      'qr_svg' => $qr_svg,
      'parameters' => $this->getParameterSummary(),
      'created' => $this->time->getRequestTime(),
    ];

    $this->logger->notice('Created Traccar device @id (@identifier) in group @group.', [
      '@id' => $result['device_id'],
      '@identifier' => $identifier,
      '@group' => $result['group_name'],
    ]);

    return $result;
  }

  /**
   * Builds the URL understood by the Traccar Client QR code scanner.
   *
   * @param string $identifier
   *   The device identifier.
   *
   * @return string
   *   The configuration URL.
   *
   * @throws \Drupal\traccar_provisioning\Exception\ProvisioningException
   *   When the client URL is not configured.
   */
  public function buildConfigUrl(string $identifier): string {
    $config = $this->configFactory->get('traccar_provisioning.settings');

    $base = trim((string) $config->get('client_url'));
    if ($base === '') {
      throw new ProvisioningException("L'URL du client Traccar n'est pas configurée.");
    }

    $params = ['id' => $identifier];

    $accuracy = trim((string) $config->get('accuracy'));
    if ($accuracy !== '') {
      $params['accuracy'] = $accuracy;
    }

    foreach (['distance', 'interval', 'angle', 'heartbeat'] as $key) {
      $value = $config->get($key);
      if ($value !== NULL && $value !== '') {
        $params[$key] = (string) (int) $value;
      }
    }

    foreach (['buffer', 'wakelock', 'stop_detection', 'prefer_platform_providers'] as $key) {
      $value = $config->get($key);
      if ($value !== NULL && $value !== '') {
        $params[$key] = $value ? 'true' : 'false';
      }
    }

    // Keep any fragment out of the query string.
    $fragment = '';
    $hash_position = strpos($base, '#');
    if ($hash_position !== FALSE) {
      $fragment = substr($base, $hash_position);
      $base = substr($base, 0, $hash_position);
    }

    $base = rtrim($base, '?&');
    $separator = str_contains($base, '?') ? '&' : '?';
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    return $base . $separator . $query . $fragment;
  }

  /**
   * Returns the human-readable tracking parameters applied to the client.
   *
   * @return array<string, string>
   *   Parameter labels and values.
   */
  public function getParameterSummary(): array {
    $config = $this->configFactory->get('traccar_provisioning.settings');

    return [
      (string) $this->t('Précision') => (string) $config->get('accuracy'),
      (string) $this->t('Distance (mètres)') => (string) (int) $config->get('distance'),
      (string) $this->t('Intervalle (secondes)') => (string) (int) $config->get('interval'),
      (string) $this->t('Angle (degrés)') => (string) (int) $config->get('angle'),
      (string) $this->t('Heartbeat stationnaire (secondes)') => (string) (int) $config->get('heartbeat'),
      (string) $this->t('Buffer hors ligne') => $config->get('buffer') ? (string) $this->t('Oui') : (string) $this->t('Non'),
      (string) $this->t('Wake lock') => $config->get('wakelock') ? (string) $this->t('Oui') : (string) $this->t('Non'),
      (string) $this->t('Détection des arrêts') => $config->get('stop_detection') ? (string) $this->t('Oui') : (string) $this->t('Non'),
      (string) $this->t('Fournisseurs de localisation système') => $config->get('prefer_platform_providers') ? (string) $this->t('Oui') : (string) $this->t('Non'),
    ];
  }

}
