<?php

namespace Drupal\traccar_provisioning\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\traccar_provisioning\Exception\ProvisioningException;
use Drupal\traccar_provisioning\Service\ProvisioningManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves QR code images for Traccar Client configurations.
 */
class QrController extends ControllerBase {

  /**
   * The provisioning manager.
   */
  protected ProvisioningManager $provisioningManager;

  /**
   * The logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = parent::create($container);
    $instance->provisioningManager = $container->get('traccar_provisioning.provisioning_manager');
    $instance->logger = $container->get('logger.channel.traccar_provisioning');
    return $instance;
  }

  /**
   * Generates a QR code image from query parameters.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The image response, or a plain text error response.
   */
  public function generate(Request $request): Response {
    $config = $this->config('traccar_provisioning.settings');
    $expected_key = trim((string) $config->get('qr_api_key'));

    if ($expected_key === '') {
      return $this->errorResponse('L’endpoint QR est désactivé. Configurez une clé API QR dans les paramètres du module.', 403);
    }

    $provided_key = (string) $request->query->get('key', '');
    if (!hash_equals($expected_key, $provided_key)) {
      return $this->errorResponse('Clé API QR invalide.', 403);
    }

    $identifier = trim((string) $request->query->get('id', ''));
    if ($identifier === '') {
      return $this->errorResponse('Le paramètre id est obligatoire.', 400);
    }

    $identifier_error = $this->provisioningManager->validateIdentifier($identifier);
    if ($identifier_error !== NULL) {
      return $this->errorResponse($identifier_error, 400);
    }

    $tracking = $this->getTrackingFromQuery($request->query->all());
    $size = $request->query->get('size');
    $size = ($size !== NULL && is_numeric($size)) ? (int) $size : NULL;
    $format = strtolower((string) $request->query->get('format', 'png'));

    try {
      if ($format === 'svg') {
        $data = $this->provisioningManager->generateQrSvg($identifier, $tracking, $size);
        $content_type = 'image/svg+xml';
      }
      else {
        $data = $this->provisioningManager->generateQrPng($identifier, $tracking, $size);
        $content_type = 'image/png';
      }
    }
    catch (ProvisioningException $exception) {
      return $this->errorResponse($exception->getMessage(), 400);
    }
    catch (\RuntimeException $exception) {
      $this->logger->error('Unable to generate QR code for @identifier: @message', [
        '@identifier' => $identifier,
        '@message' => $exception->getMessage(),
      ]);
      return $this->errorResponse('Impossible de générer le QR code.', 500);
    }

    return new Response($data, 200, [
      'Content-Type' => $content_type,
      'Cache-Control' => 'public, max-age=86400',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

  /**
   * Converts query string values to tracking parameter types.
   *
   * @param array $query
   *   The request query parameters.
   *
   * @return array
   *   Tracking parameter overrides.
   */
  protected function getTrackingFromQuery(array $query): array {
    $tracking = [];

    if (isset($query['accuracy'])) {
      $tracking['accuracy'] = (string) $query['accuracy'];
    }

    foreach (['distance', 'interval', 'angle', 'heartbeat'] as $key) {
      if (isset($query[$key]) && is_numeric($query[$key])) {
        $tracking[$key] = (int) $query[$key];
      }
    }

    foreach (['buffer', 'wakelock', 'stop_detection', 'prefer_platform_providers'] as $key) {
      if (!isset($query[$key])) {
        continue;
      }
      $value = filter_var($query[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
      if ($value !== NULL) {
        $tracking[$key] = $value;
      }
    }

    return $tracking;
  }

  /**
   * Builds a plain text error response.
   *
   * @param string $message
   *   The error message.
   * @param int $status
   *   The HTTP status code.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  protected function errorResponse(string $message, int $status): Response {
    return new Response($message, $status, [
      'Content-Type' => 'text/plain; charset=UTF-8',
      'Cache-Control' => 'no-store',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

}
