<?php

namespace Drupal\commerce_montonio\Service;

use Drupal\Core\Site\Settings;

/**
 * Factory for creating WebhookValidator instances with configured IP allowlist.
 *
 * Reads the allowed IP addresses from Drupal settings so that the
 * WebhookValidator itself remains free of static global state and is
 * independently testable with any IP list.
 */
class WebhookValidatorFactory {

  /**
   * Creates a WebhookValidator populated from Drupal settings.
   *
   * Reads the 'commerce_montonio_webhook_allowed_ips' setting from settings.php
   * and passes it to the validator constructor. Override in settings.php via:
   *   $settings['commerce_montonio_webhook_allowed_ips'] = ['1.2.3.4'];
   * Set to an empty array to disable IP validation and rely on JWT only.
   *
   * @param \Drupal\commerce_montonio\Service\MontonioLogger $logger
   *   The logger service.
   *
   * @return \Drupal\commerce_montonio\Service\WebhookValidator
   *   A configured WebhookValidator instance.
   */
  public static function create(MontonioLogger $logger): WebhookValidator {
    $allowedIps = Settings::get(
      'commerce_montonio_webhook_allowed_ips',
      ['35.156.245.42', '35.156.159.169'],
    );

    return new WebhookValidator($logger, $allowedIps);
  }

}
