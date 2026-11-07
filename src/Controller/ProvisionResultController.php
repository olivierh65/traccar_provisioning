<?php

namespace Drupal\traccar_provisioning\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Displays the generated Traccar client configuration.
 */
class ProvisionResultController extends ControllerBase {

  /**
   * The private temp store factory.
   */
  protected PrivateTempStoreFactory $privateTempStoreFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = parent::create($container);
    $instance->privateTempStoreFactory = $container->get('tempstore.private');
    return $instance;
  }

  /**
   * Renders a provisioning result from the current user's private temp store.
   *
   * @param string $token
   *   The result token.
   *
   * @return array
   *   A render array.
   */
  public function view(string $token): array {
    $store = $this->privateTempStoreFactory->get('traccar_provisioning');
    $result = $store->get($token);

    if (!is_array($result) || empty($result['config_url'])) {
      throw new NotFoundHttpException();
    }

    return [
      '#theme' => 'traccar_provision_result',
      '#identifier' => $result['identifier'] ?? '',
      '#name' => $result['name'] ?? '',
      '#device_id' => $result['device_id'] ?? 0,
      '#group_name' => $result['group_name'] ?? '',
      '#config_url' => $result['config_url'],
      '#qr_svg' => $result['qr_svg'] ?? '',
      '#parameters' => $result['parameters'] ?? [],
      '#created' => $result['created'] ?? NULL,
      '#attached' => [
        'library' => ['traccar_provisioning/provisioning'],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
