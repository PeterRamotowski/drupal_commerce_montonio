<?php

namespace Drupal\commerce_montonio\Exception;

/**
 * Exception thrown when Montonio API requests fail.
 */
class MontonioApiException extends MontonioException {

  /**
   * The HTTP status code.
   */
  protected int $httpStatusCode;

  /**
   * The API response body.
   */
  protected ?string $responseBody;

  /**
   * Constructs a new MontonioApiException object.
   *
   * @param string $message
   *   The exception message.
   * @param int $httpStatusCode
   *   The HTTP status code.
   * @param string|null $responseBody
   *   The API response body.
   * @param \Throwable|null $previous
   *   The previous exception.
   * @param array $context
   *   Additional context data.
   */
  public function __construct(
    string $message,
    int $httpStatusCode = 0,
    ?string $responseBody = NULL,
    ?\Throwable $previous = NULL,
    array $context = [],
  ) {
    $context['http_status_code'] = $httpStatusCode;
    $context['response_body'] = $responseBody;

    parent::__construct($message, $httpStatusCode, $previous, $context);

    $this->httpStatusCode = $httpStatusCode;
    $this->responseBody = $responseBody;
  }

  /**
   * Gets the HTTP status code.
   */
  public function getHttpStatusCode(): int {
    return $this->httpStatusCode;
  }

  /**
   * Gets the API response body.
   */
  public function getResponseBody(): ?string {
    return $this->responseBody;
  }

}
