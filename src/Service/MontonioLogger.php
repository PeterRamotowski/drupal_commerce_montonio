<?php

namespace Drupal\commerce_montonio\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Psr\Log\LogLevel;

/**
 * Service for logging Montonio payment gateway activities.
 */
class MontonioLogger {

  /**
   * The logger channel.
   */
  protected LoggerChannelInterface $logger;

  public function __construct(LoggerChannelInterface $logger) {
    $this->logger = $logger;
  }

  /**
   * Logs a message to the commerce_montonio channel.
   *
   * @param string $level
   *   Log level (e.g., 'error', 'warning', 'info', 'debug').
   * @param string $message
   *   The log message.
   * @param array $context
   *   Context array.
   */
  public function log(string $level, string $message, array $context = []): void {
    $this->logger->log($level, $message, $context);
  }

  /**
   * Logs an error message.
   *
   * @param string $message
   *   The log message.
   * @param array $context
   *   Context array.
   */
  public function error(string $message, array $context = []): void {
    $this->log(LogLevel::ERROR, $message, $context);
  }

  /**
   * Logs a warning message.
   *
   * @param string $message
   *   The log message.
   * @param array $context
   *   Context array.
   */
  public function warning(string $message, array $context = []): void {
    $this->log(LogLevel::WARNING, $message, $context);
  }

  /**
   * Logs an info message.
   *
   * @param string $message
   *   The log message.
   * @param array $context
   *   Context array.
   */
  public function info(string $message, array $context = []): void {
    $this->log(LogLevel::INFO, $message, $context);
  }

  /**
   * Logs a debug message.
   *
   * @param string $message
   *   The log message.
   * @param array $context
   *   Context array.
   */
  public function debug(string $message, array $context = []): void {
    $this->log(LogLevel::DEBUG, $message, $context);
  }

}
