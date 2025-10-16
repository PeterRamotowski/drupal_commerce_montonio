<?php

namespace Drupal\commerce_montonio\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_montonio\Exception\MontonioApiException;

/**
 * Montonio API client service.
 */
class MontonioApiClient implements MontonioApiClientInterface {

  /**
   * Production API base URL.
   */
  public const PRODUCTION_URL = 'https://stargate.montonio.com/api';

  /**
   * Sandbox API base URL.
   */
  public const SANDBOX_URL = 'https://sandbox-stargate.montonio.com/api';

  /**
   * Access key.
   *
   * @var string
   */
  protected string $accessKey;

  /**
   * Secret key.
   *
   * @var string
   */
  protected string $secretKey;

  /**
   * Whether to use sandbox mode.
   *
   * @var bool
   */
  protected bool $sandboxMode;

  /**
   * Whether to enable debug logging.
   *
   * @var bool
   */
  protected bool $debug;

  /**
   * Constructs a new MontonioApiClient object.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\commerce_montonio\Service\MontonioLogger $montonioLogger
   *   The logger service.
   * @param \Drupal\commerce_montonio\Service\MontonioJwtService $jwtService
   *   The JWT service for token operations.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected MontonioLogger $montonioLogger,
    protected MontonioJwtService $jwtService,
  ) {}

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
  public function setConfiguration(string $access_key, string $secret_key, bool $sandbox_mode = FALSE, bool $debug = FALSE): void {
    $this->accessKey = $access_key;
    $this->secretKey = $secret_key;
    $this->sandboxMode = $sandbox_mode;
    $this->debug = $debug;
    $this->jwtService->setConfiguration($access_key, $secret_key);
  }

  /**
   * Gets the API base URL.
   *
   * @return string
   *   The API base URL.
   */
  protected function getBaseUrl() {
    return $this->sandboxMode ? self::SANDBOX_URL : self::PRODUCTION_URL;
  }

  /**
   * Determines if debug mode is enabled.
   *
   * @return bool
   *   TRUE if debug mode is enabled, FALSE otherwise.
   */
  protected function isDebugEnabled(): bool {
    return $this->debug;
  }

  /**
   * Generates a JWT token.
   *
   * @param array $payload
   *   The JWT payload.
   * @param int $expiryTime
   *   Token expiry time in minutes (default: 10).
   *
   * @return string
   *   The JWT token.
   */
  public function generateToken(array $payload, int $expiryTime = 10): string {
    return $this->jwtService->generateToken($payload, $expiryTime);
  }

  /**
   * Validates and decodes a JWT token.
   *
   * @param string $token
   *   The JWT token.
   *
   * @return \Drupal\commerce_montonio\Dto\MontonioTokenDto
   *   The decoded token DTO.
   */
  public function decodeToken(string $token): MontonioTokenDto {
    return $this->jwtService->decodeToken($token);
  }

  /**
   * Gets available payment methods.
   *
   * @return array
   *   Payment methods data.
   *
   * @throws \Drupal\commerce_montonio\Exception\MontonioApiException
   *   If the API request fails.
   */
  public function getPaymentMethods(): array {
    try {
      $token = $this->generateToken([]);
      $response = $this->httpClient->request('GET', $this->getBaseUrl() . '/stores/payment-methods', [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type' => 'application/json',
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (json_last_error() !== JSON_ERROR_NONE && $this->isDebugEnabled()) {
        throw new MontonioApiException('Invalid JSON response from payment methods API.', $response->getStatusCode(), $response->getBody()->getContents());
      }

      if (!$data || !isset($data['paymentMethods'])) {
        return [];
      }

      return $data['paymentMethods'];
    }
    catch (RequestException $e) {
      $this->montonioLogger->error('Failed to get payment methods: @error', ['@error' => $e->getMessage()]);

      if ($this->isDebugEnabled()) {
        throw new MontonioApiException('Failed to get payment methods: ' . $e->getMessage(), $e->getCode(), NULL, $e, [
          'url' => $this->getBaseUrl() . '/stores/payment-methods',
        ]);
      }
      return [];
    }
  }

  /**
   * Creates an order.
   *
   * @param array $orderData
   *   The order data.
   *
   * @return array
   *   The order response data.
   *
   * @throws \Drupal\commerce_montonio\Exception\MontonioApiException
   *   If the API request fails.
   */
  public function createOrder(array $orderData): array {
    try {
      $token = $this->generateToken($orderData);

      $response = $this->httpClient->request('POST', $this->getBaseUrl() . '/orders', [
        'headers' => [
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'data' => $token,
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      if (json_last_error() !== JSON_ERROR_NONE && $this->isDebugEnabled()) {
        throw new MontonioApiException('Invalid JSON response from create order API.', $response->getStatusCode(), $response->getBody()->getContents());
      }

      return $data;
    }
    catch (RequestException $e) {
      $this->montonioLogger->error('Failed to create order: @error', ['@error' => $e->getMessage()]);

      if ($this->isDebugEnabled()) {
        throw new MontonioApiException('Failed to create order: ' . $e->getMessage(), $e->getCode(), NULL, $e, [
          'url' => $this->getBaseUrl() . '/orders',
        ]);
      }
      return [];
    }
  }

  /**
   * Gets order status by UUID.
   *
   * @param string $orderUuid
   *   The Montonio order UUID.
   *
   * @return array
   *   The order data.
   *
   * @throws \Drupal\commerce_montonio\Exception\MontonioApiException
   *   If the API request fails.
   */
  public function getOrder(string $orderUuid): array {
    try {
      $token = $this->generateToken([]);
      $response = $this->httpClient->request('GET', $this->getBaseUrl() . '/orders/' . $orderUuid, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type' => 'application/json',
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      if (json_last_error() !== JSON_ERROR_NONE && $this->isDebugEnabled()) {
        throw new MontonioApiException('Invalid JSON response from get order API.', $response->getStatusCode(), $response->getBody()->getContents());
      }

      return $data;
    }
    catch (RequestException $e) {
      $this->montonioLogger->error('Failed to get order: @error', ['@error' => $e->getMessage()]);

      if ($this->isDebugEnabled()) {
        throw new MontonioApiException('Failed to get order: ' . $e->getMessage(), $e->getCode(), NULL, $e, [
          'url' => $this->getBaseUrl() . '/orders/' . $orderUuid,
        ]);
      }
      return [];
    }
  }

}
