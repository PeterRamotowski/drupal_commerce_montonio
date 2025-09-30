<?php

namespace Drupal\commerce_montonio\Service;

/**
 * Value object for Montonio payment gateway configuration.
 *
 * Provides type-safe access to configuration values and validates
 * that required configuration is present.
 */
class MontonioConfiguration {

  /**
   * The access key for Montonio API.
   */
  private string $accessKey;

  /**
   * The secret key for Montonio API.
   */
  private string $secretKey;

  /**
   * The default payment method.
   */
  private string $defaultPaymentMethod;

  /**
   * The enabled payment methods.
   */
  private array $enabledPaymentMethods;

  /**
   * Whether the gateway is in sandbox mode.
   */
  private bool $sandboxMode;

  /**
   * Whether to enable debug logging.
   */
  private bool $debug;

  /**
   * Constructs a new MontonioConfiguration object.
   *
   * @param string $accessKey
   *   The access key.
   * @param string $secretKey
   *   The secret key.
   * @param string $defaultPaymentMethod
   *   The default payment method.
   * @param array $enabledPaymentMethods
   *   The enabled payment methods.
   * @param bool $sandboxMode
   *   Whether to use sandbox mode.
   * @param bool $debug
   *   Whether to enable debug logging.
   */
  public function __construct(
    string $accessKey,
    string $secretKey,
    string $defaultPaymentMethod = 'blik',
    array $enabledPaymentMethods = [],
    bool $sandboxMode = FALSE,
    bool $debug = FALSE,
  ) {
    $this->ensureValidConfiguration($accessKey, $secretKey, $defaultPaymentMethod, $enabledPaymentMethods);

    $this->accessKey = $accessKey;
    $this->secretKey = $secretKey;
    $this->defaultPaymentMethod = $defaultPaymentMethod;
    $this->enabledPaymentMethods = $enabledPaymentMethods;
    $this->sandboxMode = $sandboxMode;
    $this->debug = $debug;
  }

  /**
   * Creates a configuration object from array data.
   *
   * @param array $configuration
   *   The configuration array.
   * @param bool $sandboxMode
   *   Whether to use sandbox mode.
   *
   * @return self
   *   The configuration object.
   */
  public static function fromArray(array $configuration, bool $sandboxMode = FALSE): self {
    return new self(
      $configuration['access_key'] ?? '',
      $configuration['secret_key'] ?? '',
      $configuration['default_payment_method'] ?? 'blik',
      $configuration['enabled_payment_methods'] ?? [],
      $sandboxMode,
      $configuration['debug'] ?? FALSE
    );
  }

  /**
   * Gets the access key.
   */
  public function getAccessKey(): string {
    return $this->accessKey;
  }

  /**
   * Gets the secret key.
   */
  public function getSecretKey(): string {
    return $this->secretKey;
  }

  /**
   * Gets the default payment method.
   */
  public function getDefaultPaymentMethod(): string {
    return $this->defaultPaymentMethod;
  }

  /**
   * Gets the default payment method from enabled methods.
   *
   * @param array $enabledMethods
   *   The currently enabled payment methods.
   *
   * @return string|null
   *   The default payment method ID, or null if no methods are enabled.
   */
  public function getDefaultPaymentMethodForEnabledMethods(array $enabledMethods): ?string {
    if (empty($enabledMethods)) {
      return NULL;
    }

    if (isset($enabledMethods[$this->defaultPaymentMethod])) {
      return $this->defaultPaymentMethod;
    }

    return key($enabledMethods);
  }

  /**
   * Gets the enabled payment methods.
   */
  public function getEnabledPaymentMethods(): array {
    return $this->enabledPaymentMethods;
  }

  /**
   * Checks if a payment method is enabled.
   *
   * @param string $method
   *   The payment method to check.
   *
   * @return bool
   *   TRUE if the method is enabled, FALSE otherwise.
   */
  public function isPaymentMethodEnabled(string $method): bool {
    return in_array($method, $this->enabledPaymentMethods, TRUE);
  }

  /**
   * Checks if the gateway is in sandbox mode.
   */
  public function isSandboxMode(): bool {
    return $this->sandboxMode;
  }

  /**
   * Checks if debug logging is enabled.
   */
  public function isDebugEnabled(): bool {
    return $this->debug;
  }

  /**
   * Validates the configuration values.
   *
   * @param string $accessKey
   *   The access key.
   * @param string $secretKey
   *   The secret key.
   * @param string $defaultPaymentMethod
   *   The default payment method.
   * @param array $enabledPaymentMethods
   *   The enabled payment methods.
   *
   * @throws \InvalidArgumentException
   *   If the configuration is invalid.
   */
  private function ensureValidConfiguration(
    string $accessKey,
    string $secretKey,
    string $defaultPaymentMethod,
    array $enabledPaymentMethods,
  ): void {
    if (empty($accessKey)) {
      throw new \InvalidArgumentException('Access key is required.');
    }

    if (empty($secretKey)) {
      throw new \InvalidArgumentException('Secret key is required.');
    }

    if (empty($enabledPaymentMethods)) {
      throw new \InvalidArgumentException('At least one payment method must be enabled.');
    }

    if (!in_array($defaultPaymentMethod, $enabledPaymentMethods, TRUE)) {
      throw new \InvalidArgumentException('Default payment method must be one of the enabled methods.');
    }
  }

}
