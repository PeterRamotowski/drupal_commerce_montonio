<?php

namespace Drupal\commerce_montonio\Exception;

/**
 * Exception thrown when JWT token operations fail.
 */
class MontonioJwtException extends MontonioException {

  /**
   * Constructs a new MontonioJwtException object.
   *
   * @param string $message
   *   The exception message.
   * @param array $context
   *   Additional context data.
   * @param \Throwable|null $previous
   *   The previous exception.
   */
  public function __construct(string $message, array $context = [], ?\Throwable $previous = NULL) {
    parent::__construct($message, 0, $previous, $context);
  }

}
