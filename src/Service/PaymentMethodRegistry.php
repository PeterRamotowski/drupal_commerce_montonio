<?php

namespace Drupal\commerce_montonio\Service;

/**
 * Registry of known Montonio payment method identifiers and display names.
 */
final class PaymentMethodRegistry {

  /**
   * Card payments method identifier.
   */
  public const CARD_PAYMENTS = 'cardPayments';

  /**
   * Bank payment initiation method identifier.
   */
  public const PAYMENT_INITIATION = 'paymentInitiation';

  /**
   * BLIK method identifier.
   */
  public const BLIK = 'blik';

  /**
   * Buy now, pay later method identifier.
   */
  public const BNPL = 'bnpl';

  /**
   * Hire purchase method identifier.
   */
  public const HIRE_PURCHASE = 'hirePurchase';

  /**
   * Returns the API-facing (non-translated) display name map.
   *
   * @return array<string, string>
   *   Display names keyed by method identifier.
   */
  public static function apiDisplayNames(): array {
    return [
      self::CARD_PAYMENTS      => 'Pay with card',
      self::PAYMENT_INITIATION => 'Pay with your bank',
      self::BLIK               => 'Pay with BLIK',
      self::BNPL               => 'Buy now, pay later',
      self::HIRE_PURCHASE      => 'Hire purchase',
    ];
  }

  /**
   * Returns all known method identifiers.
   *
   * @return string[]
   *   An array of all known method identifiers.
   */
  public static function all(): array {
    return array_keys(self::apiDisplayNames());
  }

  /**
   * Returns the API display name for a method, with a safe fallback.
   *
   * @param string $methodId
   *   The payment method identifier.
   *
   * @return string
   *   The API display name.
   */
  public static function apiDisplayName(string $methodId): string {
    return self::apiDisplayNames()[$methodId] ?? 'Montonio';
  }

}
