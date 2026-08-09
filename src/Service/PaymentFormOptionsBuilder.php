<?php

namespace Drupal\commerce_montonio\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Builds form option arrays for the Montonio payment form.
 *
 * Encapsulates the business rules that determine which payment methods, banks,
 * and BNPL periods are eligible for a given order.
 */
class PaymentFormOptionsBuilder {

  /**
   * Supported currencies per payment method.
   */
  private const SUPPORTED_CURRENCIES = [
    PaymentMethodRegistry::PAYMENT_INITIATION => ['EUR', 'PLN'],
    PaymentMethodRegistry::CARD_PAYMENTS      => ['EUR', 'PLN'],
    PaymentMethodRegistry::BLIK               => ['PLN'],
    PaymentMethodRegistry::BNPL               => ['EUR'],
    PaymentMethodRegistry::HIRE_PURCHASE      => ['EUR'],
  ];

  /**
   * Order amount limits per method (in currency units).
   */
  private const AMOUNT_LIMITS = [
    PaymentMethodRegistry::BNPL          => ['min' => 30, 'max' => 2500],
    PaymentMethodRegistry::HIRE_PURCHASE => ['min' => 100, 'max' => 10000],
  ];

  /**
   * BNPL period amount limits.
   */
  private const BNPL_PERIODS = [
    1 => ['min' => 30, 'max' => 800],
    2 => ['min' => 75, 'max' => 2500],
    3 => ['min' => 75, 'max' => 2500],
  ];

  /**
   * Default country code when billing address is unavailable.
   */
  private const DEFAULT_COUNTRY_CODE = 'EE';

  /**
   * Constructs a new PaymentFormOptionsBuilder object.
   *
   * @param \Drupal\commerce_montonio\Service\PaymentMethodValidator $paymentMethodValidator
   *   The payment method validator.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service.
   */
  public function __construct(
    private PaymentMethodValidator $paymentMethodValidator,
    private TranslationInterface $translation,
  ) {}

  /**
   * Builds eligible payment method options for a given order.
   *
   * @param array $availableMethods
   *   Payment methods returned from the Montonio API, keyed by method ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The current order.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Options array keyed by method identifier.
   */
  public function buildMethodOptions(array $availableMethods, OrderInterface $order): array {
    $currency    = $order->getTotalPrice()->getCurrencyCode();
    $countryCode = $this->resolveCountryCode($order);
    $amount      = (float) $order->getTotalPrice()->getNumber();
    $options     = [];

    foreach ($availableMethods as $methodId => $methodData) {
      if (!$this->methodSupportsCurrency($methodId, $currency)) {
        continue;
      }
      if (!$this->orderMeetsAmountLimits($methodId, $amount)) {
        continue;
      }
      if (!$this->paymentMethodValidator->methodSupportsOrder($methodData, $currency, $countryCode)) {
        continue;
      }
      $options[$methodId] = $this->getPaymentMethodLabel($methodId);
    }

    return $options;
  }

  /**
   * Builds bank options for the payment initiation method.
   *
   * @param array $paymentInitiationData
   *   The paymentInitiation entry from the Montonio API response.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The current order.
   *
   * @return array<string, array{label: string, image: string}>
   *   Options array keyed by bank code.
   */
  public function buildBankOptions(array $paymentInitiationData, OrderInterface $order): array {
    $options     = [];
    $currency    = $order->getTotalPrice()->getCurrencyCode();
    $countryCode = $this->resolveCountryCode($order);

    foreach ($paymentInitiationData['setup'][$countryCode]['paymentMethods'] ?? [] as $bank) {
      if (in_array($currency, $bank['supportedCurrencies'], TRUE)) {
        $options[$bank['code']] = [
          'label' => $bank['name'],
          'image' => $bank['logoUrl'],
        ];
      }
    }

    return $options;
  }

  /**
   * Builds BNPL period options eligible for the given order amount.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The current order.
   *
   * @return array<int, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Options array keyed by period number, values are translatable labels.
   */
  public function buildBnplPeriodOptions(OrderInterface $order): array {
    $amount  = (float) $order->getTotalPrice()->getNumber();
    $options = [];

    foreach (self::BNPL_PERIODS as $period => $limits) {
      if ($amount >= $limits['min'] && $amount <= $limits['max']) {
        $options[$period] = match ($period) {
          1 => $this->translation->translate('Pay next month'),
          2 => $this->translation->translate('Split into 2 monthly installments'),
          3 => $this->translation->translate('Split into 3 monthly installments'),
        };
      }
    }

    return $options;
  }

  /**
   * Checks if a payment method supports the given currency.
   *
   * @param string $methodId
   *   The payment method identifier.
   * @param string $currency
   *   The currency code.
   *
   * @return bool
   *   TRUE if the method supports the currency or has no restriction.
   */
  private function methodSupportsCurrency(string $methodId, string $currency): bool {
    if (!isset(self::SUPPORTED_CURRENCIES[$methodId])) {
      return TRUE;
    }
    return in_array($currency, self::SUPPORTED_CURRENCIES[$methodId], TRUE);
  }

  /**
   * Checks if the order amount meets a method's minimum and maximum limits.
   *
   * @param string $methodId
   *   The payment method identifier.
   * @param float $amount
   *   The order amount.
   *
   * @return bool
   *   TRUE if the amount is within limits or the method has none.
   */
  private function orderMeetsAmountLimits(string $methodId, float $amount): bool {
    if (!isset(self::AMOUNT_LIMITS[$methodId])) {
      return TRUE;
    }
    $limits = self::AMOUNT_LIMITS[$methodId];
    return $amount >= $limits['min'] && $amount <= $limits['max'];
  }

  /**
   * Gets the translated checkout label for a payment method.
   *
   * @param string $methodId
   *   The payment method identifier.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The translated payment method label.
   */
  private function getPaymentMethodLabel(string $methodId): TranslatableMarkup {
    return match ($methodId) {
      PaymentMethodRegistry::CARD_PAYMENTS => $this->translation->translate('Pay with card'),
      PaymentMethodRegistry::PAYMENT_INITIATION => $this->translation->translate('Pay with your bank'),
      PaymentMethodRegistry::BLIK => $this->translation->translate('Pay with BLIK'),
      PaymentMethodRegistry::BNPL => $this->translation->translate('Buy now, pay later'),
      PaymentMethodRegistry::HIRE_PURCHASE => $this->translation->translate('Hire purchase'),
      default => $this->translation->translate('Montonio'),
    };
  }

  /**
   * Resolves the billing country code from the order, defaulting to 'EE'.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return string
   *   The ISO 3166-1 alpha-2 country code.
   */
  private function resolveCountryCode(OrderInterface $order): string {
    $billingProfile = $order->getBillingProfile();
    if (!$billingProfile) {
      return self::DEFAULT_COUNTRY_CODE;
    }

    /** @var \Drupal\address\AddressInterface|null $address */
    $address = $billingProfile->get('address')->first();
    return $address?->getCountryCode() ?: self::DEFAULT_COUNTRY_CODE;
  }

}
