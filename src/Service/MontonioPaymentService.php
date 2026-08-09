<?php

namespace Drupal\commerce_montonio\Service;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_montonio\Event\PaymentEventDispatcher;
use Drupal\commerce_montonio\Service\PaymentStatus\PaymentStatusStrategyManager;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Main service for Montonio payment operations.
 *
 * This service coordinates all payment-related operations.
 */
class MontonioPaymentService {

  /**
   * Constructs a new MontonioPaymentService object.
   *
   * @param \Drupal\commerce_montonio\Event\PaymentEventDispatcher $eventDispatcher
   *   The Montonio payment event dispatcher.
   * @param \Drupal\commerce_montonio\Service\MontonioApiClientFactory $apiClientFactory
   *   The Montonio API client factory.
   * @param \Drupal\commerce_montonio\Service\PaymentMethodValidator $paymentMethodValidator
   *   The payment method validator.
   * @param \Drupal\commerce_montonio\Service\PaymentStatus\PaymentStatusStrategyManager $statusStrategyManager
   *   The payment status strategy manager.
   * @param \Drupal\commerce_montonio\Service\OrderRequestBuilder $orderRequestBuilder
   *   The order request builder.
   */
  public function __construct(
    private PaymentEventDispatcher $eventDispatcher,
    private MontonioApiClientFactory $apiClientFactory,
    private PaymentMethodValidator $paymentMethodValidator,
    private PaymentStatusStrategyManager $statusStrategyManager,
    private OrderRequestBuilder $orderRequestBuilder,
  ) {}

  /**
   * Processes a payment for the given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order to process payment for.
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway
   *   The payment gateway.
   * @param string $paymentMethod
   *   The selected payment method.
   * @param string|null $preferredBank
   *   The preferred bank for payment initiation.
   * @param int|null $bnplPeriod
   *   The BNPL period (1, 2, or 3).
   *
   * @return array
   *   The payment redirect data.
   *
   * @throws \Drupal\commerce_montonio\Exception\MontonioException
   *   If payment processing fails.
   */
  public function processPayment(
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
    string $paymentMethod,
    ?string $preferredBank = NULL,
    ?int $bnplPeriod = NULL,
  ): array {
    $apiClient = $this->apiClientFactory->createFromPaymentGateway($gateway);
    $config = $gateway->getPlugin()->getConfiguration();

    $this->paymentMethodValidator->validateEnabledPaymentMethods(
      $config['enabled_payment_methods'] ?? []
    );
    $this->paymentMethodValidator->validateDefaultPaymentMethod(
      $config['default_payment_method'] ?? 'blik',
      $config['enabled_payment_methods'] ?? []
    );

    $availableMethods = $apiClient->getPaymentMethods();
    $this->paymentMethodValidator->validatePaymentMethodSelection($paymentMethod, $availableMethods);
    $this->paymentMethodValidator->validateBankSelection($preferredBank, $paymentMethod);

    $orderData = $this->orderRequestBuilder->build($order, $gateway, $paymentMethod, $preferredBank, $bnplPeriod);

    return $apiClient->createOrder($orderData);
  }

  /**
   * Processes a webhook notification.
   *
   * @param \Drupal\commerce_montonio\Dto\MontonioTokenDto $token
   *   The decoded webhook token.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway
   *   The payment gateway entity.
   *
   * @throws \Drupal\commerce_montonio\Exception\MontonioException
   *   If webhook processing fails.
   */
  public function processWebhook(
    MontonioTokenDto $token,
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
  ): void {
    $this->statusStrategyManager->processPaymentStatus($token, $order, $gateway);
    $this->eventDispatcher->dispatchPaymentStatusEvent($token, $order, $gateway);
  }

}
