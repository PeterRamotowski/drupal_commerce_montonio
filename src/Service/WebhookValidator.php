<?php

namespace Drupal\commerce_montonio\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Service for validating Montonio webhooks.
 */
class WebhookValidator {

  /**
   * Constructs a new MontonioApiClient object.
   *
   * @param \Drupal\commerce_montonio\Service\MontonioLogger $montonioLogger
   *   The logger service.
   */
  public function __construct(
    protected MontonioLogger $montonioLogger,
  ) {}

  /**
   * Validates that the webhook request came from Montonio.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return bool
   *   TRUE if the request is valid, FALSE otherwise.
   */
  public function validateWebhookSource(Request $request): bool {
    // Montonio IPs.
    $allowedIps = ['35.156.245.42', '35.156.159.169'];
    $clientIp = $request->getClientIp();

    if (!in_array($clientIp, $allowedIps)) {
      $this->montonioLogger->warning('Webhook received from unauthorized IP: @ip', [
        '@ip' => $clientIp,
      ]);
      return FALSE;
    }

    return TRUE;
  }

}
