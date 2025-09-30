<?php

namespace Drupal\commerce_montonio\PluginForm;

use Drupal\commerce_montonio\Service\MontonioConfiguration;
use Drupal\commerce_montonio\Service\MontonioPaymentService;
use Drupal\commerce_montonio\Service\PaymentMethodValidator;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Off-site payment form for Montonio.
 */
class MontonioOffsiteForm extends PaymentOffsiteForm implements ContainerInjectionInterface {

  /**
   * Default country code.
   */
  public const DEFAULT_COUNTRY_CODE = 'EE';

  public function __construct(
    protected MontonioPaymentService $paymentService,
    protected PaymentMethodValidator $paymentMethodValidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_montonio.payment_service'),
      $container->get('commerce_montonio.payment_method_validator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    $order = $payment->getOrder();

    /** @var \Drupal\commerce_montonio\Plugin\Commerce\PaymentGateway\Montonio $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $configuration = $payment_gateway_plugin->getConfiguration();

    $enabledMethods = $payment_gateway_plugin->getEnabledPaymentMethods();
    $options = $this->buildPaymentMethodOptions($enabledMethods, $order);

    if (empty($options)) {
      return $this->processDefaultPayment($form, $form_state, $payment, $order, $configuration);
    }

    $montonioConfiguration = MontonioConfiguration::fromArray(
      $configuration,
      $payment_gateway_plugin->getMode() === 'test'
    );
    $defaultMethod = $montonioConfiguration->getDefaultPaymentMethodForEnabledMethods($enabledMethods);

    $form['payment_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Choose your payment method'),
      '#options' => $options,
      '#default_value' => $defaultMethod,
      '#required' => TRUE,
      '#weight' => -10,
    ];

    if (isset($enabledMethods['paymentInitiation'])) {
      $bankOptions = $this->buildBankOptions($enabledMethods['paymentInitiation'], $order);
      if (!empty($bankOptions)) {
        $form['preferred_bank'] = [
          '#type' => 'image_radios',
          '#title' => $this->t('Select your bank'),
          '#options' => $bankOptions,
          '#weight' => -9,
          '#wrapper_attributes' => [
            'style' => $defaultMethod !== 'paymentInitiation' ? 'display: none;' : '',
          ],
          '#states' => [
            'visible' => [
              ':input[name="payment_process[offsite_payment][payment_method]"]' => ['value' => 'paymentInitiation'],
            ],
            'required' => [
              ':input[name="payment_process[offsite_payment][payment_method]"]' => ['value' => 'paymentInitiation'],
            ],
          ],
        ];
      }
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Proceed to payment'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    if (isset($form['payment_method'])) {
      $this->validatePaymentMethodSelection($form, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!isset($form['payment_method'])) {
      return;
    }

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    $order = $payment->getOrder();

    /** @var \Drupal\commerce_montonio\Plugin\Commerce\PaymentGateway\Montonio $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $configuration = $payment_gateway_plugin->getConfiguration();
    $enabledMethods = $payment_gateway_plugin->getEnabledPaymentMethods();

    $this->processPaymentMethodSelection($form, $form_state, $payment, $order, $configuration, $enabledMethods);
  }

  /**
   * Validates the payment method selection.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function validatePaymentMethodSelection(array $form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();

    $selectedMethod = isset($form['payment_method'])
      ? NestedArray::getValue($values, $form['payment_method']['#parents'])
      : NULL;

    if (empty($selectedMethod)) {
      $form_state->setError($form['payment_method'], $this->t('Please choose a payment method.'));
    }

    if (
      $selectedMethod === 'paymentInitiation'
      && isset($form['preferred_bank'])
      && empty(NestedArray::getValue($values, $form['preferred_bank']['#parents']))
    ) {
      $form_state->setError($form['preferred_bank'], $this->t('Please select your bank.'));
    }
  }

  /**
   * Process payment method selection and redirect to Montonio.
   */
  protected function processPaymentMethodSelection(array $form, FormStateInterface $form_state, $payment, $order, array $configuration, array $enabledMethods = []): array {
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $paymentGateway */
    $paymentGateway = $payment->getPaymentGateway();
    $values = $form_state->getValues();

    $selectedMethod = isset($form['payment_method'])
      ? NestedArray::getValue($values, $form['payment_method']['#parents'])
      : NULL;

    if (!$selectedMethod && !empty($enabledMethods)) {
      $montonioConfiguration = MontonioConfiguration::fromArray(
        $configuration,
        $paymentGateway->getPlugin()->getMode() === 'test'
      );
      $selectedMethod = $montonioConfiguration->getDefaultPaymentMethodForEnabledMethods($enabledMethods);
    }

    $preferredBank = NULL;
    if (isset($form['preferred_bank'])) {
      $preferredBank = NestedArray::getValue($values, $form['preferred_bank']['#parents']);
    }

    $response = $this->paymentService->processPayment(
      $order,
      $paymentGateway,
      $selectedMethod,
      $preferredBank
    );

    if (!$response || !isset($response['paymentUrl'])) {
      throw new \Exception('Failed to create payment order with Montonio.');
    }

    return $this->buildRedirectForm($form, $form_state, $response['paymentUrl'], [], self::REDIRECT_GET);
  }

  /**
   * Process default payment without selection.
   */
  protected function processDefaultPayment(array $form, FormStateInterface $form_state, $payment, $order, array $configuration): array {
    /** @var \Drupal\commerce_montonio\Plugin\Commerce\PaymentGateway\Montonio $paymentGatewayPlugin */
    $paymentGatewayPlugin = $payment->getPaymentGateway()->getPlugin();
    $enabledMethods = $paymentGatewayPlugin->getEnabledPaymentMethods();

    $montonioConfiguration = MontonioConfiguration::fromArray(
      $configuration,
      $paymentGatewayPlugin->getMode() === 'test'
    );
    $defaultMethod = $montonioConfiguration->getDefaultPaymentMethodForEnabledMethods($enabledMethods);

    $response = $this->paymentService->processPayment(
      $order,
      $payment->getPaymentGateway(),
      $defaultMethod
    );

    if (!$response || !isset($response['paymentUrl'])) {
      throw new \Exception('Failed to create payment order with Montonio.');
    }

    return $this->buildRedirectForm($form, $form_state, $response['paymentUrl'], [], self::REDIRECT_GET);
  }

  /**
   * Builds payment method options from available methods.
   */
  protected function buildPaymentMethodOptions(array $availableMethods, OrderInterface $order): array {
    $options = [];
    $currency = $order->getTotalPrice()->getCurrencyCode();
    $countryCode = $this->getOrderCountryCode($order);

    foreach ($availableMethods as $method_id => $method_data) {
      if ($this->paymentMethodValidator->methodSupportsOrder($method_data, $currency, $countryCode)) {
        $options[$method_id] = $this->getPaymentMethodLabel($method_id);
      }
    }

    return $options;
  }

  /**
   * Builds bank options for payment initiation.
   */
  protected function buildBankOptions(array $paymentInitiationData, OrderInterface $order): array {
    $options = [];
    $currency = $order->getTotalPrice()->getCurrencyCode();
    $countryCode = $this->getOrderCountryCode($order);

    if (isset($paymentInitiationData['setup'][$countryCode]['paymentMethods'])) {
      foreach ($paymentInitiationData['setup'][$countryCode]['paymentMethods'] as $bank) {
        if (in_array($currency, $bank['supportedCurrencies'])) {
          $options[$bank['code']] = [
            'label' => $bank['name'],
            'image' => $bank['logoUrl'],
          ];
        }
      }
    }

    return $options;
  }

  /**
   * Gets the country code from the order's billing address.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return string
   *   The country code, or 'EE' as default.
   */
  protected function getOrderCountryCode(OrderInterface $order): string {
    $billingProfile = $order->getBillingProfile();

    if (!$billingProfile) {
      return self::DEFAULT_COUNTRY_CODE;
    }

    /** @var \Drupal\address\AddressInterface $billingAddress */
    $billingAddress = $billingProfile->get('address')->first();

    if (!$billingAddress || !$billingAddress->getCountryCode()) {
      return self::DEFAULT_COUNTRY_CODE;
    }

    return $billingAddress->getCountryCode();
  }

  /**
   * Gets the payment method labels.
   *
   * @return array
   *   Array of method labels.
   */
  private function getPaymentMethodLabels(): array {
    return [
      'cardPayments' => $this->t('Pay with card'),
      'paymentInitiation' => $this->t('Pay with your bank'),
      'blik' => $this->t('Pay with BLIK'),
      'bnpl' => $this->t('Buy now, pay later'),
      'hirePurchase' => $this->t('Hire purchase'),
    ];
  }

  /**
   * Gets the display label for a payment method.
   */
  protected function getPaymentMethodLabel(string $methodId): string {
    $labels = $this->getPaymentMethodLabels();

    return $labels[$methodId] ?? $this->t('Montonio @method', ['@method' => $methodId]);
  }

}
