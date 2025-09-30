<?php

namespace Drupal\commerce_montonio\Service;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;

/**
 * Interface for Montonio API client operations.
 */
interface MontonioApiClientInterface {

  /**
   * Sets the API configuration.
   *
   * @param string $access_key
   *   The access key.
   * @param string $secret_key
   *   The secret key.
   * @param bool $sandbox_mode
   *   Whether to use sandbox mode.
   * @param bool $debug
   *   Whether to enable debug logging.
   */
  public function setConfiguration(string $access_key, string $secret_key, bool $sandbox_mode = FALSE, bool $debug = FALSE): void;

  /**
   * Generates a JWT token with the given payload.
   *
   * @param array $payload
   *   The JWT payload data.
   * @param int $expiry_time
   *   Token expiry time in minutes (default: 10).
   *
   * @return string
   *   The generated JWT token.
   */
  public function generateToken(array $payload, int $expiry_time = 10): string;

  /**
   * Validates and decodes a JWT token.
   *
   * @param string $token
   *   The JWT token to decode.
   *
   * @return \Drupal\commerce_montonio\Dto\MontonioTokenDto
   *   The decoded token DTO.
   */
  public function decodeToken(string $token): MontonioTokenDto;

  /**
   * Gets available payment methods.
   *
   * @return array
   *   Payment methods data.
   */
  public function getPaymentMethods(): array;

  /**
   * Creates an order.
   *
   * @param array $order_data
   *   The order data.
   *
   * @return array
   *   The order response data.
   */
  public function createOrder(array $order_data): array;

  /**
   * Gets order status by UUID.
   *
   * @param string $order_uuid
   *   The Montonio order UUID.
   *
   * @return array
   *   The order data.
   */
  public function getOrder(string $order_uuid): array;

}
