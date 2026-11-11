<?php

namespace Drupal\traccar_provisioning\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\traccar_provisioning\Exception\TraccarApiException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Small client for the Traccar REST API.
 */
class TraccarApiClient {

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a new TraccarApiClient.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory, LoggerInterface $logger) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * Returns the Traccar API base URL, including the /api suffix.
   *
   * @param array $overrides
   *   Optional configuration overrides, used to test unsaved settings.
   *
   * @throws \Drupal\traccar_provisioning\Exception\TraccarApiException
   *   When the API URL is not configured.
   */
  public function getApiBaseUrl(array $overrides = []): string {
    $config = $this->configFactory->get('traccar_provisioning.settings');
    $url = trim((string) $this->getSetting($config, 'api_url', $overrides, ''));
    if ($url === '') {
      throw new TraccarApiException("L'URL de l'API Traccar n'est pas configurée.");
    }

    $url = rtrim($url, '/');
    if (!preg_match('#/api$#i', $url)) {
      $url .= '/api';
    }

    return $url;
  }

  /**
   * Fetches groups from Traccar and returns id => display name options.
   *
   * Sub-groups are displayed with their parent path.
   *
   * @param array $overrides
   *   Optional configuration overrides, used to test unsaved settings.
   *
   * @return array<int, string>
   *   Group options keyed by group ID.
   *
   * @throws \Drupal\traccar_provisioning\Exception\TraccarApiException
   *   When the API request fails.
   */
  public function getGroups(array $overrides = []): array {
    $data = $this->request('GET', '/groups', [], 'fetch groups', $overrides);
    if (!is_array($data)) {
      throw new TraccarApiException('Réponse inattendue du serveur Traccar pour la liste des groupes.');
    }

    $groups = [];
    foreach ($data as $group) {
      if (!is_array($group) || !isset($group['id'], $group['name'])) {
        continue;
      }
      $id = (int) $group['id'];
      if ($id <= 0) {
        continue;
      }
      $groups[$id] = [
        'name' => (string) $group['name'],
        'parent' => (int) ($group['groupId'] ?? 0),
      ];
    }

    $paths = [];
    foreach ($groups as $id => $group) {
      $names = [];
      $current_id = $id;
      $visited = [];
      while ($current_id > 0 && isset($groups[$current_id]) && !isset($visited[$current_id])) {
        $visited[$current_id] = TRUE;
        array_unshift($names, $groups[$current_id]['name']);
        $current_id = $groups[$current_id]['parent'];
      }
      $paths[$id] = implode(' > ', $names);
    }

    uasort($paths, static function (string $a, string $b): int {
      return strnatcasecmp($a, $b);
    });

    return $paths;
  }

  /**
   * Fetches calendars from Traccar and returns id => display name options.
   *
   * @param array $overrides
   *   Optional configuration overrides, used to test unsaved settings.
   *
   * @return array<int, string>
   *   Calendar options keyed by calendar ID.
   *
   * @throws \Drupal\traccar_provisioning\Exception\TraccarApiException
   *   When the API request fails.
   */
  public function getCalendars(array $overrides = []): array {
    $data = $this->request('GET', '/calendars', [], 'fetch calendars', $overrides);
    if (!is_array($data)) {
      throw new TraccarApiException('Réponse inattendue du serveur Traccar pour la liste des calendriers.');
    }

    $calendars = [];
    foreach ($data as $calendar) {
      if (!is_array($calendar) || !isset($calendar['id'])) {
        continue;
      }
      $id = (int) $calendar['id'];
      if ($id <= 0) {
        continue;
      }
      $name = trim((string) ($calendar['name'] ?? ''));
      $calendars[$id] = $name !== '' ? $name : 'Calendrier #' . $id;
    }

    uasort($calendars, static function (string $a, string $b): int {
      return strnatcasecmp($a, $b);
    });

    return $calendars;
  }

  /**
   * Finds a device by its exact unique identifier.
   *
   * @param string $unique_id
   *   The Traccar device identifier.
   *
   * @return array|null
   *   The device data, or NULL when it does not exist.
   */
  public function findDeviceByUniqueId(string $unique_id): ?array {
    $path = '/devices?all=true&keyword=' . rawurlencode($unique_id);
    $data = $this->request('GET', $path, [], 'find device');

    if (!is_array($data)) {
      return NULL;
    }

    foreach ($data as $device) {
      if (is_array($device) && isset($device['uniqueId']) && (string) $device['uniqueId'] === $unique_id) {
        return $device;
      }
    }

    return NULL;
  }

  /**
   * Creates a device on the Traccar server.
   *
   * @param string $unique_id
   *   The device identifier.
   * @param string $name
   *   The display name.
   * @param int $group_id
   *   The group ID, or 0 for no group.
   * @param array $extra
   *   Optional additional device properties, for example phone, model,
   *   contact, category, calendarId or expirationTime.
   *
   * @return array
   *   The created device data.
   *
   * @throws \Drupal\traccar_provisioning\Exception\TraccarApiException
   *   When the API request fails.
   */
  public function createDevice(string $unique_id, string $name, int $group_id = 0, array $extra = []): array {
    $payload = [
      'name' => $name,
      'uniqueId' => $unique_id,
      'groupId' => $group_id,
    ];

    foreach (['phone', 'model', 'contact', 'category', 'calendarId', 'expirationTime'] as $key) {
      if (!array_key_exists($key, $extra) || $extra[$key] === NULL || $extra[$key] === '') {
        continue;
      }
      $payload[$key] = $extra[$key];
    }

    $data = $this->request('POST', '/devices', [
      'json' => $payload,
    ], 'create device');

    if (!is_array($data) || empty($data['id'])) {
      throw new TraccarApiException("Traccar n'a pas renvoyé l'appareil créé.");
    }

    return $data;
  }

  /**
   * Deletes a device from the Traccar server.
   *
   * @param int $device_id
   *   The internal Traccar device ID.
   *
   * @throws \Drupal\traccar_provisioning\Exception\TraccarApiException
   *   When the API request fails.
   */
  public function deleteDevice(int $device_id): void {
    if ($device_id <= 0) {
      throw new TraccarApiException("L'ID de l'appareil est invalide.");
    }

    $this->request('DELETE', '/devices/' . $device_id, [], 'delete device');
  }

  /**
   * Checks the connection and authentication by fetching groups.
   *
   * @param array $overrides
   *   Optional configuration overrides, used to test unsaved settings.
   *
   * @return array<int, string>
   *   The available groups.
   */
  public function testConnection(array $overrides = []): array {
    return $this->getGroups($overrides);
  }

  /**
   * Performs an authenticated API request.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The API path, for example /groups.
   * @param array $options
   *   Additional Guzzle request options.
   * @param string $context
   *   A short context string for logs.
   * @param array $overrides
   *   Optional configuration overrides, used to test unsaved settings.
   *
   * @return array
   *   The decoded JSON response.
   *
   * @throws \Drupal\traccar_provisioning\Exception\TraccarApiException
   *   When the request fails.
   */
  protected function request(string $method, string $path, array $options = [], string $context = '', array $overrides = []): array {
    $config = $this->configFactory->get('traccar_provisioning.settings');
    $timeout = max(1, (int) ($this->getSetting($config, 'timeout', $overrides, 10) ?: 10));

    $options += [
      'headers' => $this->getAuthHeaders($overrides),
      'timeout' => $timeout,
      'verify' => (bool) $this->getSetting($config, 'verify_ssl', $overrides, TRUE),
      'http_errors' => FALSE,
      'allow_redirects' => FALSE,
    ];
    $options['headers']['Accept'] = 'application/json';
    $options['headers']['User-Agent'] = 'Drupal Traccar Provisioning/1.0';

    $url = $this->getApiBaseUrl($overrides) . '/' . ltrim($path, '/');

    try {
      $response = $this->httpClient->request($method, $url, $options);
    }
    catch (GuzzleException $exception) {
      $this->logger->warning('Traccar API @context failed: @message', [
        '@context' => $context ?: $method . ' ' . $path,
        '@message' => $exception->getMessage(),
      ]);
      throw new TraccarApiException('Impossible de contacter le serveur Traccar : ' . $exception->getMessage(), 0, '', $exception);
    }

    $status = $response->getStatusCode();
    $body = (string) $response->getBody();

    if ($status >= 200 && $status < 300) {
      if (trim($body) === '') {
        return [];
      }

      $decoded = json_decode($body, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new TraccarApiException('Réponse invalide du serveur Traccar : JSON attendu.', $status, $body);
      }

      return is_array($decoded) ? $decoded : ['data' => $decoded];
    }

    $message = $this->buildErrorMessage($status, $body);
    $this->logger->warning('Traccar API @context returned HTTP @status: @message', [
      '@context' => $context ?: $method . ' ' . $path,
      '@status' => $status,
      '@message' => $message,
    ]);

    throw new TraccarApiException($message, $status, $body);
  }

  /**
   * Builds the authentication headers from module configuration.
   *
   * @param array $overrides
   *   Optional configuration overrides, used to test unsaved settings.
   *
   * @return array<string, string>
   *   HTTP headers.
   *
   * @throws \Drupal\traccar_provisioning\Exception\TraccarApiException
   *   When credentials are missing.
   */
  protected function getAuthHeaders(array $overrides = []): array {
    $config = $this->configFactory->get('traccar_provisioning.settings');

    if ((string) $this->getSetting($config, 'auth_method', $overrides, 'token') === 'basic') {
      $username = trim((string) $this->getSetting($config, 'username', $overrides, ''));
      $password = (string) $this->getSetting($config, 'password', $overrides, '');
      if ($username === '' || $password === '') {
        throw new TraccarApiException("Les identifiants Traccar ne sont pas configurés.");
      }
      return [
        'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
      ];
    }

    $token = trim((string) $this->getSetting($config, 'api_token', $overrides, ''));
    if ($token === '') {
      throw new TraccarApiException("Le token API Traccar n'est pas configuré.");
    }

    return [
      'Authorization' => 'Bearer ' . $token,
    ];
  }

  /**
   * Reads a setting, preferring a runtime override when present.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The module configuration.
   * @param string $key
   *   The configuration key.
   * @param array $overrides
   *   Runtime overrides.
   * @param mixed $default
   *   Default value when the key is not set.
   *
   * @return mixed
   *   The resolved value.
   */
  protected function getSetting(ImmutableConfig $config, string $key, array $overrides, mixed $default = NULL): mixed {
    if (array_key_exists($key, $overrides)) {
      return $overrides[$key];
    }
    $value = $config->get($key);
    return $value ?? $default;
  }

  /**
   * Builds a user-friendly error message from a Traccar error response.
   */
  protected function buildErrorMessage(int $status, string $body): string {
    $detail = $this->extractApiMessage($body);

    $message = match ($status) {
      400 => "La requête a été refusée par Traccar.",
      401 => 'Authentification Traccar refusée. Vérifiez le token ou les identifiants dans la configuration du module.',
      403 => "Le compte Traccar n'a pas les droits nécessaires pour effectuer cette opération.",
      404 => "Endpoint Traccar introuvable. Vérifiez l'URL de l'API.",
      500, 502, 503, 504 => 'Le serveur Traccar a renvoyé une erreur (HTTP ' . $status . ').',
      default => 'Erreur du serveur Traccar (HTTP ' . $status . ').',
    };

    return $detail !== '' ? $message . ' ' . $detail : $message;
  }

  /**
   * Extracts a readable message from a Traccar error body.
   */
  protected function extractApiMessage(string $body): string {
    $body = trim($body);
    if ($body === '') {
      return '';
    }

    $decoded = json_decode($body, TRUE);
    if (is_array($decoded)) {
      foreach (['message', 'error', 'error_description'] as $key) {
        if (!empty($decoded[$key]) && is_string($decoded[$key])) {
          return trim($decoded[$key]);
        }
      }
    }

    $text = trim(strip_tags($body));
    if (mb_strlen($text) > 500) {
      $text = mb_substr($text, 0, 500) . '…';
    }

    return $text;
  }

}
