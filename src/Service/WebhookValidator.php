<?php

namespace Drupal\commerce_montonio\Service;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_montonio\Exception\MontonioJwtException;
use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Service for validating Montonio webhooks.
 *
 * The IP allowlist defaults to Montonio-published server IPs and can be
 * overridden by injecting a custom list via the constructor (or factory).
 * Set to an empty array to disable IP validation and rely on JWT only.
 */
class WebhookValidator {

  /**
   * Constructs a new WebhookValidator object.
   *
   * @param \Drupal\commerce_montonio\Service\MontonioLogger $montonioLogger
   *   The logger service.
   * @param string[] $allowedIps
   *   IP addresses allowed to submit webhooks. An empty array disables IP
   *   checking and relies solely on JWT signature validation.
   */
  public function __construct(
    protected MontonioLogger $montonioLogger,
    private readonly array $allowedIps = [],
  ) {}

  /**
   * Validates that the webhook request came from an allowed IP address.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return bool
   *   TRUE if the request is valid, FALSE otherwise.
   */
  public function validateWebhookSource(Request $request): bool {
    // Empty array means IP checking is disabled; rely on JWT signature only.
    if (empty($this->allowedIps)) {
      return TRUE;
    }

    $clientIp = $request->getClientIp();

    if (!in_array($clientIp, $this->allowedIps, TRUE)) {
      $this->montonioLogger->warning('Webhook received from unauthorized IP: @ip', [
        '@ip' => $clientIp,
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Validates that the webhook token payload matches the local order.
   *
   * Checks UUID presence, payment status presence, merchant reference,
   * currency, and grand total to prevent data corruption or fraud.
   *
   * @param \Drupal\commerce_montonio\Dto\MontonioTokenDto $token
   *   The decoded webhook token.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The local order entity.
   *
   * @throws \Drupal\commerce_montonio\Exception\MontonioJwtException
   *   If any field fails validation.
   */
  public function validateTokenMatchesOrder(
    MontonioTokenDto $token,
    OrderInterface $order,
  ): void {
    if (!$token->getUuid() || !$token->getPaymentStatus()) {
      throw new MontonioJwtException(
        'Webhook token is missing required fields (uuid, paymentStatus).',
      );
    }

    if ($token->getMerchantReference() !== $order->getOrderNumber()) {
      throw new MontonioJwtException(sprintf(
        'Webhook merchant reference "%s" does not match order number "%s".',
        $token->getMerchantReference(),
        $order->getOrderNumber(),
      ));
    }

    $orderTotal = $order->getTotalPrice();
    if ($orderTotal === NULL) {
      return;
    }

    if ($token->getCurrency() !== $orderTotal->getCurrencyCode()) {
      throw new MontonioJwtException(sprintf(
        'Webhook currency "%s" does not match order currency "%s".',
        $token->getCurrency(),
        $orderTotal->getCurrencyCode(),
      ));
    }

    $grandTotal = $token->getGrandTotal();
    if ($grandTotal === NULL || !preg_match('/^-?\d+(?:\.\d+)?$/D', $grandTotal)) {
      throw new MontonioJwtException(
        'Webhook token contains an invalid grand total.',
      );
    }

    if (bccomp($grandTotal, $orderTotal->getNumber(), 2) !== 0) {
      throw new MontonioJwtException(sprintf(
        'Webhook amount "%s" does not match order total "%s".',
        $grandTotal,
        $orderTotal->getNumber(),
      ));
    }
  }

}
