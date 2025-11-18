<?php

namespace Drupal\commerce_montonio\Service\PaymentStatus;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_price\Price;

/**
 * Provides shared helpers for refund-oriented payment strategies.
 */
abstract class AbstractRefundPaymentStrategy extends AbstractPaymentStatusStrategy {

  /**
   * Resolves the refund amount provided by Montonio.
   *
   * @param \Drupal\commerce_montonio\Dto\MontonioTokenDto $token
   *   The decoded Montonio token.
   *
   * @return \Drupal\commerce_price\Price|null
   *   The refund price or NULL when missing.
   */
  protected function resolveRefundPrice(MontonioTokenDto $token): ?Price {
    $amount = $token->getGrandTotal();
    $currency = $token->getCurrency();

    if ($amount === NULL || $currency === NULL) {
      return NULL;
    }

    return new Price((string) $amount, $currency);
  }

}
