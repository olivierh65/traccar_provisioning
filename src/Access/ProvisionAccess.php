<?php

namespace Drupal\traccar_provisioning\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access check for the front-office provisioning pages.
 */
class ProvisionAccess {

  /**
   * Grants access to users who can use or administer the module.
   */
  public static function access(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf(
      $account->hasPermission('use traccar provisioning') ||
      $account->hasPermission('administer traccar provisioning')
    )->cachePerPermissions();
  }

}
