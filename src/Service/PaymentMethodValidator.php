<?php

namespace Drupal\commerce_montonio\Service;

use Drupal\commerce_montonio\Exception\MontonioConfigurationException;

/**
 * Service for validating payment method configurations and selections.
 */
class PaymentMethodValidator {

  /**
   * Validates that at least one payment method is enabled.
   *
   * @param array $enabledPaymentMethods
   *   The enabled payment methods.
   *
   * @throws \Drupal\commerce_montonio\Exception\MontonioConfigurationException
   *   If no payment methods are enabled.
   */
  public function validateEnabledPaymentMethods(array $enabledPaymentMethods): void {
    if (empty($enabledPaymentMethods)) {
      throw new MontonioConfigurationException('At least one payment method must be enabled.');
    }
  }

  /**
   * Validates that the default payment method is enabled.
   *
   * @param string $defaultPaymentMethod
   *   The default payment method.
   * @param array $enabledPaymentMethods
   *   The enabled payment methods.
   *
   * @throws \Drupal\commerce_montonio\Exception\MontonioConfigurationException
   *   If the default payment method is not enabled.
   */
  public function validateDefaultPaymentMethod(string $defaultPaymentMethod, array $enabledPaymentMethods): void {
    if (!in_array($defaultPaymentMethod, $enabledPaymentMethods, TRUE)) {
      throw new MontonioConfigurationException(
        'The default payment method must be one of the enabled methods.',
        [
          'default_method' => $defaultPaymentMethod,
          'enabled_methods' => $enabledPaymentMethods,
        ]
      );
    }
  }

  /**
   * Validates payment method selection for an order.
   *
   * @param string|null $selectedMethod
   *   The selected payment method.
   * @param array $availableMethods
   *   The available payment methods.
   *
   * @throws \Drupal\commerce_montonio\Exception\MontonioConfigurationException
   *   If the payment method selection is invalid.
   */
  public function validatePaymentMethodSelection(?string $selectedMethod, array $availableMethods): void {
    if (empty($selectedMethod)) {
      throw new MontonioConfigurationException('Please choose a payment method.');
    }

    if (!isset($availableMethods[$selectedMethod])) {
      throw new MontonioConfigurationException(
        'Selected payment method is not available.',
        [
          'selected_method' => $selectedMethod,
          'available_methods' => array_keys($availableMethods),
        ]
      );
    }
  }

  /**
   * Validates bank selection for payment initiation.
   *
   * @param string|null $selectedBank
   *   The selected bank.
   * @param string $paymentMethod
   *   The payment method (should be 'paymentInitiation').
   *
   * @throws \Drupal\commerce_montonio\Exception\MontonioConfigurationException
   *   If bank selection is required but missing.
   */
  public function validateBankSelection(?string $selectedBank, string $paymentMethod): void {
    if ($paymentMethod === 'paymentInitiation' && empty($selectedBank)) {
      throw new MontonioConfigurationException('Please select your bank.');
    }
  }

  /**
   * Checks if a payment method supports the order's currency and country.
   *
   * @param array $methodData
   *   The payment method data from Montonio API.
   * @param string $currency
   *   The order currency code.
   * @param string $countryCode
   *   The order billing country code.
   *
   * @return bool
   *   TRUE if the method supports the currency and country, FALSE otherwise.
   */
  public function methodSupportsOrder(array $methodData, string $currency, string $countryCode): bool {
    // For payment initiation, check by country and currency.
    if (isset($methodData['setup'])) {
      if (isset($methodData['setup'][$countryCode]['supportedCurrencies'])) {
        return in_array($currency, $methodData['setup'][$countryCode]['supportedCurrencies'], TRUE);
      }
    }

    // For other methods, assume EUR and PLN support based on typical setup.
    $supportedCurrencies = ['EUR'];
    if (in_array($currency, ['PLN'], TRUE) && isset($methodData['processor'])) {
      $supportedCurrencies[] = 'PLN';
    }

    return in_array($currency, $supportedCurrencies, TRUE);
  }

}
