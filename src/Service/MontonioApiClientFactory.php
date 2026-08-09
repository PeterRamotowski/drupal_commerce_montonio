<?php

namespace Drupal\commerce_montonio\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface as PaymentGatewayPluginInterface;
use GuzzleHttp\ClientInterface;

/**
 * Factory service for creating configured Montonio API clients.
 *
 * Each call to a create* method returns a freshly constructed client with
 * its own isolated JWT service and credentials, avoiding credential bleed
 * across gateways within a single request.
 */
class MontonioApiClientFactory {

  /**
   * Constructs a new MontonioApiClientFactory object.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\commerce_montonio\Service\MontonioLogger $logger
   *   The Montonio logger service.
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   The cache backend for payment method caching.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected MontonioLogger $logger,
    protected ?CacheBackendInterface $cache = NULL,
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
   *   A freshly constructed and configured API client instance.
   */
  public function createFromConfiguration(array $configuration, bool $sandboxMode = FALSE): MontonioApiClient {
    $client = new MontonioApiClient(
      $this->httpClient,
      $this->logger,
      new MontonioJwtService(),
      $this->cache,
    );
    $client->setConfiguration(
      $configuration['access_key'],
      $configuration['secret_key'],
      $sandboxMode,
      (bool) ($configuration['debug'] ?? FALSE),
    );

    return $client;
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
