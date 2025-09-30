<?php

namespace Drupal\commerce_montonio\Exception;

/**
 * Base exception class for Montonio payment gateway errors.
 */
class MontonioException extends \Exception {

  /**
   * The error context data.
   */
  protected array $context;

  /**
   * Constructs a new MontonioException.
   *
   * @param string $message
   *   The exception message.
   * @param int $code
   *   The exception code.
   * @param \Throwable|null $previous
   *   The previous exception.
   * @param array $context
   *   Additional context data.
   */
  public function __construct(string $message, int $code = 0, ?\Throwable $previous = NULL, array $context = []) {
    parent::__construct($message, $code, $previous);
    $this->context = $context;
  }

  /**
   * Gets the error context data.
   */
  public function getContext(): array {
    return $this->context;
  }

}