<?php

namespace Drupal\commerce_montonio\Service;

use Drupal\commerce_montonio\Dto\DtoFactory;
use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_montonio\Event\PaymentEventDispatcher;
use Drupal\commerce_montonio\Event\PaymentStatusEvent;
use Drupal\commerce_montonio\Service\PaymentStatus\PaymentStatusStrategyManager;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Main service for Montonio payment operations.
 *
 * This service coordinates all payment-related operations.
 */
class MontonioPaymentService {

  public function __construct(
    private EventDispatcherInterface $symfonyEventDispatcher,
    private PaymentEventDispatcher $eventDispatcher,
    private MontonioApiClientFactory $apiClientFactory,
    private PaymentMethodValidator $paymentMethodValidator,
    private PaymentStatusStrategyManager $statusStrategyManager,
    private DtoFactory $dtoFactory,
    private OrderNumber $orderNumber,
    private LanguageManagerInterface $languageManager,
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
  ): array {
    $apiClient = $this->apiClientFactory->createFromPaymentGateway($gateway);

    // Validate payment method configuration.
    $this->paymentMethodValidator->validateEnabledPaymentMethods(
      $gateway->getPlugin()->getConfiguration()['enabled_payment_methods'] ?? []
    );
    $this->paymentMethodValidator->validateDefaultPaymentMethod(
      $gateway->getPlugin()->getConfiguration()['default_payment_method'] ?? 'blik',
      $gateway->getPlugin()->getConfiguration()['enabled_payment_methods'] ?? []
    );

    // Validate payment method selection.
    $availableMethods = $apiClient->getPaymentMethods();
    $this->paymentMethodValidator->validatePaymentMethodSelection($paymentMethod, $availableMethods);
    $this->paymentMethodValidator->validateBankSelection($preferredBank, $paymentMethod);

    $orderData = $this->buildOrderData($order, $gateway, $paymentMethod, $preferredBank);

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

    // Dispatch events for external listeners.
    $this->eventDispatcher->dispatchPaymentStatusEvent($token, $order, $gateway);

    // Dispatch Symfony event for Drupal integration.
    $event = new PaymentStatusEvent($token, $order, $gateway);
    $this->symfonyEventDispatcher->dispatch($event, PaymentStatusEvent::PAYMENT_STATUS_CHANGED);
  }

  /**
   * Builds order data for the Montonio API.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway
   *   The payment gateway entity.
   * @param string $paymentMethod
   *   The selected payment method.
   * @param string|null $preferredBank
   *   The preferred bank.
   *
   * @return array
   *   The order data array.
   */
  protected function buildOrderData(
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
    string $paymentMethod,
    ?string $preferredBank = NULL,
  ): array {
    $amount = $order->getTotalPrice();
    $billingProfile = $order->getBillingProfile();

    /** @var \Drupal\address\AddressInterface|null $billingAddress */
    $billingAddress = $billingProfile ? $billingProfile->get('address')->first() : NULL;

    $montonioBillingAddress = [];
    if ($billingAddress) {
      $montonioBillingAddress = $this->dtoFactory
        ->createAddressDto($billingAddress, $order->getEmail())
        ->toArray();
    }

    $shippingProfile = NULL;

    /** @var \Drupal\address\AddressInterface|null $shippingAddress */
    $shippingAddress = $shippingProfile ? $shippingProfile->get('address')->first() : NULL;

    $montonioShippingAddress = $montonioBillingAddress;
    if ($shippingAddress) {
      $montonioShippingAddress = $this->dtoFactory
        ->createAddressDto($shippingAddress, $order->getEmail())
        ->toArray();
    }

    $lineItems = [];
    foreach ($order->getItems() as $orderItem) {
      $lineItems[] = [
        'name' => $orderItem->getTitle(),
        'quantity' => (int) $orderItem->getQuantity(),
        'finalPrice' => (float) $orderItem->getTotalPrice()->getNumber(),
      ];
    }

    // Build payment method options.
    $methodOptions = [];
    if ($paymentMethod === 'paymentInitiation') {
      $methodOptions = [
        'paymentDescription' => 'Payment for order ' . $order->getOrderNumber(),
        'preferredCountry' => $montonioBillingAddress['country'] ?? 'EE',
      ];

      if ($preferredBank) {
        $methodOptions['preferredProvider'] = $preferredBank;
      }
    }

    $this->orderNumber->setOrderNumber($order);
    $order->save();

    $merchantReference = $order->getOrderNumber() ?: (string) $order->id();

    return [
      'merchantReference' => $merchantReference,
      'returnUrl' => $this->buildReturnUrl($order),
      'notificationUrl' => $this->buildNotificationUrl($gateway),
      'currency' => $amount->getCurrencyCode(),
      'grandTotal' => (float) $amount->getNumber(),
      'locale' => $this->languageManager->getCurrentLanguage()->getId(),
      'billingAddress' => $montonioBillingAddress,
      'shippingAddress' => $montonioShippingAddress,
      'lineItems' => $lineItems,
      'payment' => [
        'method' => $paymentMethod,
        'methodDisplay' => $this->getPaymentMethodDisplayName($paymentMethod),
        'methodOptions' => $methodOptions,
        'amount' => (float) $amount->getNumber(),
        'currency' => $amount->getCurrencyCode(),
      ],
    ];
  }

  /**
   * Builds the return URL for the payment.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return string
   *   The return URL.
   */
  protected function buildReturnUrl(OrderInterface $order): string {
    return Url::fromRoute('commerce_payment.checkout.return', [
      'commerce_order' => $order->id(),
      'step' => 'payment',
    ], ['absolute' => TRUE])->toString();
  }

  /**
   * Builds the notification URL for webhooks.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway
   *   The payment gateway entity.
   *
   * @return string
   *   The notification URL.
   */
  protected function buildNotificationUrl(PaymentGatewayInterface $gateway): string {
    return Url::fromRoute('commerce_montonio.webhook', [
      'commerce_payment_gateway' => $gateway->id(),
    ], ['absolute' => TRUE])->toString();
  }

  /**
   * Gets the display name for a payment method.
   *
   * @param string $method
   *   The payment method identifier.
   *
   * @return string
   *   The display name.
   */
  protected function getPaymentMethodDisplayName(string $method): string {
    $names = [
      'cardPayments' => 'Pay with card',
      'paymentInitiation' => 'Pay with your bank',
      'blik' => 'Pay with BLIK',
      'bnpl' => 'Buy now, pay later',
      'hirePurchase' => 'Hire purchase',
    ];

    return $names[$method] ?? 'Montonio';
  }

}
