<?php

namespace Drupal\commerce_montonio\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_montonio\Exception\MontonioJwtException;

/**
 * Service for handling Montonio JWT token operations.
 *
 * This service encapsulates all JWT-related functionality including
 * token generation, validation, and decoding.
 */
class MontonioJwtService {

  /**
   * The access key for JWT operations.
   */
  protected string $accessKey;

  /**
   * The secret key for JWT operations.
   */
  protected string $secretKey;

  /**
   * Sets the JWT configuration.
   *
   * @param string $access_key
   *   The access key.
   * @param string $secret_key
   *   The secret key.
   */
  public function setConfiguration(string $access_key, string $secret_key): void {
    $this->accessKey = $access_key;
    $this->secretKey = $secret_key;
  }

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
  public function generateToken(array $payload, int $expiry_time = 10): string {
    $payload['accessKey'] = $this->accessKey;
    $payload['iat'] = time();
    $payload['exp'] = time() + ($expiry_time * 60);

    return JWT::encode($payload, $this->secretKey, 'HS256');
  }

  /**
   * Validates and decodes a JWT token.
   *
   * @param string $token
   *   The JWT token to decode.
   *
   * @return \Drupal\commerce_montonio\Dto\MontonioTokenDto
   *   The decoded token DTO.
   *
   * @throws \Drupal\commerce_montonio\Exception\MontonioJwtException
   *   If the token is invalid or cannot be decoded.
   */
  public function decodeToken(string $token): MontonioTokenDto {
    if (empty($token)) {
      throw new MontonioJwtException('Token cannot be empty.', ['token' => $token]);
    }

    try {
      $decoded = JWT::decode($token, new Key($this->secretKey, 'HS256'));
      return new MontonioTokenDto($decoded);
    }
    catch (\Exception $e) {
      throw new MontonioJwtException('Token validation failed: ' . $e->getMessage(), [
        'token' => $token,
        'original_error' => $e->getMessage(),
      ], $e);
    }
  }

}
