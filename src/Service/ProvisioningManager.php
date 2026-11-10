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
   * Returns the calendars configured on the Traccar server.
   *
   * @return array<int, string>
   *   Calendar options keyed by calendar ID.
   */
  public function getCalendars(): array {
    return $this->apiClient->getCalendars();
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
   * @param array $device_data
   *   Optional additional device fields: phone, model, contact, category,
   *   calendar_id and expiration (Y-m-d date).
   * @param array $tracking
   *   Optional tracking parameters overriding the module defaults: accuracy,
   *   distance, interval, angle, heartbeat, buffer, wakelock, stop_detection
   *   and prefer_platform_providers.
   *
   * @return array
   *   Provisioning result data.
   *
   * @throws \Drupal\traccar_provisioning\Exception\ProvisioningException
   *   When the request is invalid.
   * @throws \Drupal\traccar_provisioning\Exception\TraccarApiException
   *   When the Traccar API request fails.
   */
  public function createProvisioning(string $identifier, string $name, int $group_id = 0, array $device_data = [], array $tracking = []): array {
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

    [$extra, $details] = $this->prepareDeviceData($device_data);
    $tracking = $this->normalizeTracking($tracking);

    // Build the client configuration before creating the remote device so a
    // local configuration or QR generation error cannot leave an orphan device.
    $config_url = $this->buildConfigUrl($identifier, $tracking);
    $qr_svg = $this->qrGenerator->generateSvg($config_url);

    $device = $this->apiClient->createDevice($identifier, $name, $group_id, $extra);

    $result = [
      'identifier' => $identifier,
      'name' => $name,
      'device_id' => (int) $device['id'],
      'group_id' => $group_id,
      'group_name' => $groups[$group_id] ?? (string) $this->t('Aucun groupe'),
      'config_url' => $config_url,
      'qr_svg' => $qr_svg,
      'parameters' => $this->getParameterSummary($tracking),
      'device_details' => $details,
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
   * Normalizes optional device fields and builds a human-readable summary.
   *
   * @param array $device_data
   *   Raw optional device fields from the provisioning form.
   *
   * @return array
   *   A tuple with the Traccar API payload and the display details.
   *
   * @throws \Drupal\traccar_provisioning\Exception\ProvisioningException
   *   When a provided value is invalid.
   */
  protected function prepareDeviceData(array $device_data): array {
    $extra = [];
    $details = [];

    $labels = [
      'phone' => (string) $this->t('Téléphone'),
      'model' => (string) $this->t('Modèle'),
      'contact' => (string) $this->t('Contact'),
      'category' => (string) $this->t('Catégorie'),
    ];

    foreach ($labels as $key => $label) {
      $value = trim((string) ($device_data[$key] ?? ''));
      if ($value === '') {
        continue;
      }
      if (mb_strlen($value) > 255) {
        throw new ProvisioningException(sprintf('Le champ « %s » ne peut pas dépasser 255 caractères.', $label));
      }
      $extra[$key] = $value;
      $details[$label] = $value;
    }

    $calendar_id = (int) ($device_data['calendar_id'] ?? 0);
    if ($calendar_id > 0) {
      $calendars = $this->getCalendars();
      if (!isset($calendars[$calendar_id])) {
        throw new ProvisioningException("Le calendrier sélectionné n'existe plus sur le serveur Traccar.");
      }
      $extra['calendarId'] = $calendar_id;
      $details[(string) $this->t('Calendrier')] = $calendars[$calendar_id];
    }

    $expiration = trim((string) ($device_data['expiration'] ?? ''));
    if ($expiration !== '') {
      $extra['expirationTime'] = $this->normalizeExpiration($expiration);
      $details[(string) $this->t('Expiration')] = $this->formatExpiration($expiration);
    }

    return [$extra, $details];
  }

  /**
   * Normalizes tracking parameters, falling back to module defaults.
   *
   * @param array $tracking
   *   Tracking parameter overrides.
   *
   * @return array
   *   Complete and normalized tracking parameters.
   */
  protected function normalizeTracking(array $tracking): array {
    $config = $this->configFactory->get('traccar_provisioning.settings');

    $defaults = [
      'accuracy' => (string) $config->get('accuracy') ?: 'high',
      'distance' => (int) $config->get('distance'),
      'interval' => (int) $config->get('interval'),
      'angle' => (int) $config->get('angle'),
      'heartbeat' => (int) $config->get('heartbeat'),
      'buffer' => (bool) $config->get('buffer'),
      'wakelock' => (bool) $config->get('wakelock'),
      'stop_detection' => (bool) $config->get('stop_detection'),
      'prefer_platform_providers' => (bool) $config->get('prefer_platform_providers'),
    ];

    $values = array_merge($defaults, array_intersect_key($tracking, $defaults));

    if (!in_array($values['accuracy'], ['highest', 'high', 'medium', 'low'], TRUE)) {
      $values['accuracy'] = 'high';
    }
    foreach (['distance', 'interval', 'angle', 'heartbeat'] as $key) {
      $values[$key] = max(0, (int) $values[$key]);
    }
    foreach (['buffer', 'wakelock', 'stop_detection', 'prefer_platform_providers'] as $key) {
      $values[$key] = (bool) $values[$key];
    }

    return $values;
  }

  /**
   * Returns the human-readable label for a location accuracy level.
   */
  protected function getAccuracyLabel(string $accuracy): string {
    return match ($accuracy) {
      'highest' => (string) $this->t('Maximale (GPS permanent)'),
      'high' => (string) $this->t('Haute'),
      'medium' => (string) $this->t('Moyenne'),
      'low' => (string) $this->t('Basse'),
      default => $accuracy,
    };
  }

  /**
   * Converts a date (Y-m-d) to the ISO 8601 UTC end-of-day Traccar expects.
   *
   * @param string $value
   *   The date entered in the form.
   *
   * @return string
   *   An ISO 8601 timestamp in UTC.
   *
   * @throws \Drupal\traccar_provisioning\Exception\ProvisioningException
   *   When the date is invalid.
   */
  protected function normalizeExpiration(string $value): string {
    $timezone = $this->getSiteTimezone();
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    $errors = \DateTimeImmutable::getLastErrors();
    if ($date === FALSE || ($errors !== FALSE && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
      throw new ProvisioningException("La date d'expiration est invalide.");
    }

    return $date->setTime(23, 59, 59)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
  }

  /**
   * Formats an expiration date for display.
   */
  protected function formatExpiration(string $value): string {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->getSiteTimezone());
    return $date !== FALSE ? $date->format('d/m/Y') : $value;
  }

  /**
   * Returns the site default timezone.
   */
  protected function getSiteTimezone(): \DateTimeZone {
    $name = (string) $this->configFactory->get('system.date')->get('timezone.default');
    try {
      return new \DateTimeZone($name !== '' ? $name : 'UTC');
    }
    catch (\Exception) {
      return new \DateTimeZone('UTC');
    }
  }

  /**
   * Builds the URL understood by the Traccar Client QR code scanner.
   *
   * @param string $identifier
   *   The device identifier.
   * @param array $tracking
   *   Optional tracking parameter overrides.
   *
   * @return string
   *   The configuration URL.
   *
   * @throws \Drupal\traccar_provisioning\Exception\ProvisioningException
   *   When the client URL is not configured.
   */
  public function buildConfigUrl(string $identifier, array $tracking = []): string {
    $config = $this->configFactory->get('traccar_provisioning.settings');

    $base = trim((string) $config->get('client_url'));
    if ($base === '') {
      throw new ProvisioningException("L'URL du client Traccar n'est pas configurée.");
    }

    $tracking = $this->normalizeTracking($tracking);

    $params = ['id' => $identifier];
    $params['accuracy'] = $tracking['accuracy'];

    foreach (['distance', 'interval', 'angle', 'heartbeat'] as $key) {
      $params[$key] = (string) $tracking[$key];
    }

    foreach (['buffer', 'wakelock', 'stop_detection', 'prefer_platform_providers'] as $key) {
      $params[$key] = $tracking[$key] ? 'true' : 'false';
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
   * @param array $tracking
   *   Optional tracking parameter overrides.
   *
   * @return array<string, string>
   *   Parameter labels and values.
   */
  public function getParameterSummary(array $tracking = []): array {
    $tracking = $this->normalizeTracking($tracking);

    return [
      (string) $this->t('Précision') => $this->getAccuracyLabel($tracking['accuracy']),
      (string) $this->t('Distance (mètres)') => (string) $tracking['distance'],
      (string) $this->t('Intervalle (secondes)') => (string) $tracking['interval'],
      (string) $this->t('Angle (degrés)') => (string) $tracking['angle'],
      (string) $this->t('Heartbeat stationnaire (secondes)') => (string) $tracking['heartbeat'],
      (string) $this->t('Buffer hors ligne') => $tracking['buffer'] ? (string) $this->t('Oui') : (string) $this->t('Non'),
      (string) $this->t('Wake lock') => $tracking['wakelock'] ? (string) $this->t('Oui') : (string) $this->t('Non'),
      (string) $this->t('Détection des arrêts') => $tracking['stop_detection'] ? (string) $this->t('Oui') : (string) $this->t('Non'),
      (string) $this->t('Fournisseurs de localisation système') => $tracking['prefer_platform_providers'] ? (string) $this->t('Oui') : (string) $this->t('Non'),
    ];
  }

}
