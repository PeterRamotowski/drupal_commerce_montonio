<?php

namespace Drupal\commerce_montonio\Service;

use Drupal\Core\Cache\CacheBackendInterface;
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
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   Optional cache backend for payment method responses.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected MontonioLogger $montonioLogger,
    protected MontonioJwtService $jwtService,
    protected ?CacheBackendInterface $cache = NULL,
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
    $cid = 'commerce_montonio:payment_methods:' . $this->accessKey . ':' . ($this->sandboxMode ? 'sandbox' : 'live');

    if ($this->cache !== NULL) {
      $cached = $this->cache->get($cid);
      if ($cached !== FALSE) {
        return $cached->data;
      }
    }

    $token = $this->generateToken([]);
    $data = $this->sendRequest('GET', '/stores/payment-methods', [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type'  => 'application/json',
      ],
    ], 'get payment methods');

    if (!$data || !isset($data['paymentMethods'])) {
      return [];
    }

    if ($this->cache !== NULL) {
      $this->cache->set($cid, $data['paymentMethods'], time() + 3600);
    }

    return $data['paymentMethods'];
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
    $token = $this->generateToken($orderData);

    return $this->sendRequest('POST', '/orders', [
      'headers' => ['Content-Type' => 'application/json'],
      'json'    => ['data' => $token],
    ], 'create order');
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
   *   If the API request fails or the UUID is invalid.
   */
  public function getOrder(string $orderUuid): array {
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $orderUuid)) {
      throw new MontonioApiException('Invalid Montonio order UUID: ' . $orderUuid);
    }

    $token = $this->generateToken([]);

    return $this->sendRequest('GET', '/orders/' . rawurlencode($orderUuid), [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type'  => 'application/json',
      ],
    ], 'get order');
  }

  /**
   * Sends an authenticated HTTP request and returns the decoded JSON response.
   *
   * @param string $method
   *   The HTTP method (GET, POST, etc.).
   * @param string $endpoint
   *   The API endpoint path (e.g. '/orders').
   * @param array $options
   *   Additional Guzzle request options merged with defaults.
   * @param string $errorContext
   *   A human-readable label used in error log messages.
   *
   * @return array
   *   The decoded JSON response array.
   *
   * @throws \Drupal\commerce_montonio\Exception\MontonioApiException
   *   If the request fails or the response contains invalid JSON.
   */
  private function sendRequest(
    string $method,
    string $endpoint,
    array $options,
    string $errorContext,
  ): array {
    try {
      $response = $this->httpClient->request(
        $method,
        $this->getBaseUrl() . $endpoint,
        array_merge(['connect_timeout' => 3, 'timeout' => 10], $options),
      );

      $body = (string) $response->getBody();
      $data = json_decode($body, TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new MontonioApiException(
          sprintf('Invalid JSON response from %s API.', $errorContext),
          $response->getStatusCode(),
          $body,
        );
      }

      return $data ?? [];
    }
    catch (RequestException $e) {
      $this->montonioLogger->error(
        sprintf('Failed to %s: @error', $errorContext),
        ['@error' => $e->getMessage()],
      );

      if ($this->isDebugEnabled()) {
        throw new MontonioApiException(
          sprintf('Failed to %s: %s', $errorContext, $e->getMessage()),
          $e->getCode(),
          NULL,
          $e,
          ['url' => $this->getBaseUrl() . $endpoint],
        );
      }

      return [];
    }
  }

}
