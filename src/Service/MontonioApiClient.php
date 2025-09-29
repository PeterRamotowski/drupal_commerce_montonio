<?php

namespace Drupal\commerce_montonio\Service;

use Drupal\commerce_montonio\Service\MontonioLogger;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Montonio API client service.
 */
class MontonioApiClient
{

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
  protected $accessKey;

  /**
   * Secret key.
   *
   * @var string
   */
  protected $secretKey;

  /**
   * Whether to use sandbox mode.
   *
   * @var bool
   */
  protected $sandboxMode;

  /**
   * Constructs a new MontonioApiClient object.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\commerce_montonio\Service\MontonioLogger $montonioLogger
   *   The logger service.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected MontonioLogger $montonioLogger,
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
   */
  public function setConfiguration($access_key, $secret_key, $sandbox_mode = FALSE)
  {
    $this->accessKey = $access_key;
    $this->secretKey = $secret_key;
    $this->sandboxMode = $sandbox_mode;
  }

  /**
   * Gets the API base URL.
   *
   * @return string
   *   The API base URL.
   */
  protected function getBaseUrl()
  {
    return $this->sandboxMode ? self::SANDBOX_URL : self::PRODUCTION_URL;
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
  public function generateToken(array $payload, $expiryTime = 10)
  {
    $payload['accessKey'] = $this->accessKey;
    $payload['iat'] = time();
    $payload['exp'] = time() + ($expiryTime * 60);

    return JWT::encode($payload, $this->secretKey, 'HS256');
  }

  /**
   * Validates and decodes a JWT token.
   *
   * @param string $token
   *   The JWT token.
   *
   * @return object|null
   *   The decoded token payload or NULL if invalid.
   */
  public function decodeToken($token)
  {
    try {
      return JWT::decode($token, new Key($this->secretKey, 'HS256'));
    } catch (\Exception $e) {
      $this->montonioLogger->error('Token validation failed: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Gets available payment methods.
   *
   * @return array|null
   *   Payment methods data or NULL on failure.
   */
  public function getPaymentMethods()
  {
    try {
      $token = $this->generateToken([]);
      $response = $this->httpClient->request('GET', $this->getBaseUrl() . '/stores/payment-methods', [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type' => 'application/json',
        ],
      ]);

      return json_decode($response->getBody()->getContents(), TRUE);
    } catch (RequestException $e) {
      $this->montonioLogger->error('Failed to get payment methods: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Creates an order.
   *
   * @param array $orderData
   *   The order data.
   *
   * @return array|null
   *   The order response data or NULL on failure.
   */
  public function createOrder(array $orderData)
  {
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

      return json_decode($response->getBody()->getContents(), TRUE);
    } catch (RequestException $e) {
      $this->montonioLogger->error('Failed to create order: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Gets order status by UUID.
   *
   * @param string $orderUuid
   *   The Montonio order UUID.
   *
   * @return array|null
   *   The order data or NULL on failure.
   */
  public function getOrder($orderUuid)
  {
    try {
      $token = $this->generateToken([]);
      $response = $this->httpClient->request('GET', $this->getBaseUrl() . '/orders/' . $orderUuid, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type' => 'application/json',
        ],
      ]);

      return json_decode($response->getBody()->getContents(), TRUE);
    } catch (RequestException $e) {
      $this->montonioLogger->error('Failed to get order: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }

}
