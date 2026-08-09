<?php

namespace Drupal\commerce_montonio\PluginForm;

use Drupal\commerce_montonio\Service\MontonioConfiguration;
use Drupal\commerce_montonio\Service\MontonioPaymentService;
use Drupal\commerce_montonio\Service\MontonioRedirectUrlValidator;
use Drupal\commerce_montonio\Service\PaymentFormOptionsBuilder;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Off-site payment form for Montonio.
 */
class MontonioOffsiteForm extends PaymentOffsiteForm implements ContainerInjectionInterface {

  /**
   * Default country code.
   */
  public const DEFAULT_COUNTRY_CODE = 'EE';

  /**
   * Constructs a new MontonioOffsiteForm object.
   *
   * @param \Drupal\commerce_montonio\Service\MontonioPaymentService $paymentService
   *   The Montonio payment service.
   * @param \Drupal\commerce_montonio\Service\PaymentFormOptionsBuilder $optionsBuilder
   *   The payment form options builder.
   * @param \Psr\Log\LoggerInterface $logger
   *   The Montonio logger channel.
   */
  public function __construct(
    protected MontonioPaymentService $paymentService,
    protected PaymentFormOptionsBuilder $optionsBuilder,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // @phpstan-ignore new.static
    return new static(
      $container->get('commerce_montonio.payment_service'),
      $container->get('commerce_montonio.payment_form_options_builder'),
      $container->get('logger.channel.commerce_montonio'),
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
    $options = $this->optionsBuilder->buildMethodOptions($enabledMethods, $order);

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
      $bankOptions = $this->optionsBuilder->buildBankOptions($enabledMethods['paymentInitiation'], $order);
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

    if (isset($enabledMethods['bnpl'])) {
      $bnplOptions = $this->optionsBuilder->buildBnplPeriodOptions($order);
      if (!empty($bnplOptions)) {
        $form['bnpl_period'] = [
          '#type' => 'radios',
          '#title' => $this->t('Select payment plan'),
          '#options' => $bnplOptions,
          '#default_value' => array_key_first($bnplOptions),
          '#weight' => -8,
          '#wrapper_attributes' => [
            'style' => $defaultMethod !== 'bnpl' ? 'display: none;' : '',
          ],
          '#states' => [
            'visible' => [
              ':input[name="payment_process[offsite_payment][payment_method]"]' => ['value' => 'bnpl'],
            ],
            'required' => [
              ':input[name="payment_process[offsite_payment][payment_method]"]' => ['value' => 'bnpl'],
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
    ) {
      $bankValue = NestedArray::getValue($values, $form['preferred_bank']['#parents']);
      if (empty($bankValue)) {
        $form_state->setError($form['preferred_bank'], $this->t('Please select your bank.'));
      }
      elseif (!isset($form['preferred_bank']['#options'][$bankValue])) {
        $form_state->setError($form['preferred_bank'], $this->t('Invalid bank selection.'));
      }
    }

    if (
      $selectedMethod === 'bnpl'
      && isset($form['bnpl_period'])
    ) {
      $bnplValue = NestedArray::getValue($values, $form['bnpl_period']['#parents']);
      if (empty($bnplValue)) {
        $form_state->setError($form['bnpl_period'], $this->t('Please select a payment plan.'));
      }
      elseif (!isset($form['bnpl_period']['#options'][$bnplValue])) {
        $form_state->setError($form['bnpl_period'], $this->t('Invalid payment plan selection.'));
      }
    }
  }

  /**
   * Process payment method selection and redirect to Montonio.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment entity.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param array $configuration
   *   The payment gateway plugin configuration.
   * @param array $enabledMethods
   *   The enabled payment methods.
   *
   * @return array
   *   The redirect form.
   */
  protected function processPaymentMethodSelection(
    array $form,
    FormStateInterface $form_state,
    PaymentInterface $payment,
    OrderInterface $order,
    array $configuration,
    array $enabledMethods = [],
  ): array {
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

    $bnplPeriod = NULL;
    if (isset($form['bnpl_period'])) {
      $bnplPeriod = NestedArray::getValue($values, $form['bnpl_period']['#parents']);
    }

    try {
      $response = $this->paymentService->processPayment(
        $order,
        $paymentGateway,
        $selectedMethod,
        $preferredBank,
        $bnplPeriod
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Montonio payment initiation failed for order @order: @msg', [
        '@order' => $order->id(),
        '@msg' => $e->getMessage(),
      ]);
      throw PaymentGatewayException::createForPayment(
        $payment,
        (string) $this->t('Payment could not be initiated. Please try again.'),
        previous: $e,
      );
    }

    if (!isset($response['paymentUrl']) || !is_string($response['paymentUrl'])) {
      $this->logger->error('Montonio returned no paymentUrl for order @order', ['@order' => $order->id()]);
      throw PaymentGatewayException::createForPayment(
        $payment,
        (string) $this->t('Payment could not be initiated. Please try again.'),
      );
    }

    $paymentUrl = $response['paymentUrl'];
    if (!MontonioRedirectUrlValidator::isSafe($paymentUrl)) {
      $this->logger->error('Montonio returned invalid paymentUrl for order @order: @url', [
        '@order' => $order->id(),
        '@url' => $paymentUrl,
      ]);
      throw PaymentGatewayException::createForPayment(
        $payment,
        (string) $this->t('Payment could not be initiated. Please try again.'),
      );
    }

    return $this->buildRedirectForm($form, $form_state, $paymentUrl, [], self::REDIRECT_GET);
  }

  /**
   * Process default payment without selection.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment entity.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param array $configuration
   *   The payment gateway plugin configuration.
   *
   * @return array
   *   The redirect form.
   */
  protected function processDefaultPayment(
    array $form,
    FormStateInterface $form_state,
    PaymentInterface $payment,
    OrderInterface $order,
    array $configuration,
  ): array {
    /** @var \Drupal\commerce_montonio\Plugin\Commerce\PaymentGateway\Montonio $paymentGatewayPlugin */
    $paymentGatewayPlugin = $payment->getPaymentGateway()->getPlugin();
    $enabledMethods = $paymentGatewayPlugin->getEnabledPaymentMethods();

    $montonioConfiguration = MontonioConfiguration::fromArray(
      $configuration,
      $paymentGatewayPlugin->getMode() === 'test'
    );
    $defaultMethod = $montonioConfiguration->getDefaultPaymentMethodForEnabledMethods($enabledMethods);

    try {
      $response = $this->paymentService->processPayment(
        $order,
        $payment->getPaymentGateway(),
        $defaultMethod
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Montonio default payment initiation failed for order @order: @msg', [
        '@order' => $order->id(),
        '@msg' => $e->getMessage(),
      ]);
      throw PaymentGatewayException::createForPayment(
        $payment,
        (string) $this->t('Payment could not be initiated. Please try again.'),
        previous: $e,
      );
    }

    if (!isset($response['paymentUrl']) || !is_string($response['paymentUrl'])) {
      $this->logger->error('Montonio returned no paymentUrl for order @order (default flow)', ['@order' => $order->id()]);
      throw PaymentGatewayException::createForPayment(
        $payment,
        (string) $this->t('Payment could not be initiated. Please try again.'),
      );
    }

    $paymentUrl = $response['paymentUrl'];
    if (!MontonioRedirectUrlValidator::isSafe($paymentUrl)) {
      $this->logger->error('Montonio returned invalid paymentUrl for order @order (default flow): @url', [
        '@order' => $order->id(),
        '@url' => $paymentUrl,
      ]);
      throw PaymentGatewayException::createForPayment(
        $payment,
        (string) $this->t('Payment could not be initiated. Please try again.'),
      );
    }

    return $this->buildRedirectForm($form, $form_state, $paymentUrl, [], self::REDIRECT_GET);
  }

}
