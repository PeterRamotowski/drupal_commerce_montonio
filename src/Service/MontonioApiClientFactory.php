<?php

namespace Drupal\commerce_montonio\Service;

use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface as PaymentGatewayPluginInterface;

/**
 * Factory service for creating configured Montonio API clients.
 */
class MontonioApiClientFactory {

  /**
   * Constructs a new MontonioApiClientFactory object.
   *
   * @param MontonioApiClient $apiClient
   *   Montonio API client.
   */
  public function __construct(
    protected MontonioApiClient $apiClient,
  ) {}

  /**
   * Creates a configured API client from payment gateway configuration.
   *
   * @param array $configuration
   *   The payment gateway configuration containing access_key, secret_key.
   * @param bool $sandboxMode
   *   Whether to use sandbox mode.
   *
   * @return \Drupal\commerce_montonio\Service\MontonioApiClient
   *   A configured API client instance.
   */
  public function createFromConfiguration(array $configuration, $sandboxMode = FALSE): MontonioApiClient {
    $this->apiClient->setConfiguration(
      $configuration['access_key'],
      $configuration['secret_key'],
      $sandboxMode,
      $configuration['debug'] ?? FALSE
    );

    return $this->apiClient;
  }

  /**
   * Creates a configured API client from a payment gateway entity.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $paymentGateway
   *   The payment gateway entity.
   *
   * @return \Drupal\commerce_montonio\Service\MontonioApiClient
   *   A configured API client instance.
   */
  public function createFromPaymentGateway(PaymentGatewayInterface $paymentGateway): MontonioApiClient {
    $gateway_plugin = $paymentGateway->getPlugin();
    $configuration = $gateway_plugin->getConfiguration();
    $sandboxMode = $gateway_plugin->getMode() === 'test';

    return $this->createFromConfiguration($configuration, $sandboxMode);
  }

  /**
   * Creates a configured API client from a payment gateway plugin.
   *
   * @param \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface $gatewayPlugin
   *   The payment gateway plugin.
   *
   * @return \Drupal\commerce_montonio\Service\MontonioApiClient
   *   A configured API client instance.
   */
  public function createFromPaymentGatewayPlugin(PaymentGatewayPluginInterface $gatewayPlugin): MontonioApiClient {
    $configuration = $gatewayPlugin->getConfiguration();
    $sandboxMode = $gatewayPlugin->getMode() === 'test';

    return $this->createFromConfiguration($configuration, $sandboxMode);
  }

}
