<?php
namespace Drupal\drupalcommerce_coinsnap\Plugin\Commerce\PaymentGateway;
require_once __DIR__ . '/../../../Coinsnap/library/loader.php';

if(!defined('COINSNAP_DRUPAL_VERSION')){ define( 'COINSNAP_DRUPAL_VERSION', '1.2.0' ); }
if(!defined('COINSNAP_DRUPAL_REFERRAL_CODE')){ define( 'COINSNAP_DRUPAL_REFERRAL_CODE', 'D17823' ); }
if(!defined('COINSNAP_CURRENCIES')){ define( 'COINSNAP_CURRENCIES', array("EUR","USD","SATS","BTC","CAD","JPY","GBP","CHF","RUB") ); }
if(!defined('COINSNAP_SERVER_URL')){ define( 'COINSNAP_SERVER_URL', 'https://app.coinsnap.io' );}
if(!defined('COINSNAP_API_PATH')){define( 'COINSNAP_API_PATH', '/api/v1/');}
if(!defined('COINSNAP_SERVER_PATH')){define( 'COINSNAP_SERVER_PATH', 'stores' );}

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\commerce_order\Entity\Order;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\Url;
use Drupal\drupalcommerce_coinsnap\Webhook\InvoiceDecision;
use Drupal\drupalcommerce_coinsnap\Webhook\Signature;
use Drupal\drupalcommerce_coinsnap\Webhook\BtcpayKeyCheck;
use Symfony\Component\HttpFoundation\Response;
use Drupal\commerce_payment\Exception\PaymentGatewayException;

/**
 * Provides the coinsnap payment gateway
 * @CommercePaymentGateway(
 *   id = "coinsnap",
 *   label = "Coinsnap Payment",
 *   display_label = "Coinsnap",
 *   forms = {
 *     "offsite-payment" = "Drupal\drupalcommerce_coinsnap\PluginForm\OffsiteRedirect\CoinsnapForm"
 *   }
 * )
 */

class CoinsnapRedirect extends OffsitePaymentGatewayBase {
    
    public const COINSNAP_WEBHOOK_EVENTS = ['New','Expired','Settled','Processing'];
    public const BTCPAY_WEBHOOK_EVENTS = ['InvoiceCreated','InvoiceExpired','InvoiceSettled','InvoiceProcessing'];
    
    /**
     * {@inheritdoc}
     */    
    public function defaultConfiguration(){
        return [
            'provider' => 'coinsnap',
            'store_id' => '',
            'api_key' => '',
            'webhook' => '',
            'btcpay_server_url' => '',
            'btcpay_store_id' => '',
            'btcpay_api_key' => '',
            'btcpay_webhook' => '',
            'autoredirect' => TRUE,
            'returnurl' => '',
            'discount_enabled' => TRUE,
            'discount_type' => 'fixed',
            'discount_amount' => '',
            'discount_amount_limit' => '',
            'discount_percentage' => '',
        ] + parent::defaultConfiguration();
    }
	
    
    public function buildConfigurationForm(array $form, FormStateInterface $form_state){
        
        $form = parent::buildConfigurationForm($form, $form_state);
	unset($form['mode']);

        $provider = !empty($this->configuration['provider']) ? $this->configuration['provider'] : 'coinsnap';
        $store_id = !empty($this->configuration['store_id']) ? $this->configuration['store_id'] : '';
        $api_key = !empty($this->configuration['api_key']) ? $this->configuration['api_key'] : '';
        $btcpay_server_url = !empty($this->configuration['btcpay_server_url']) ? $this->configuration['btcpay_server_url'] : '';
        $btcpay_store_id = !empty($this->configuration['btcpay_store_id']) ? $this->configuration['btcpay_store_id'] : '';
        $btcpay_api_key = !empty($this->configuration['btcpay_api_key']) ? $this->configuration['btcpay_api_key'] : '';
        
        $autoredirect = !empty($this->configuration['autoredirect']) && $this->configuration['autoredirect'] > 0 ? TRUE : FALSE;
        $returnurl = !empty($this->configuration['returnurl']) ? $this->configuration['returnurl'] : '';
        
        $discount_enabled = !empty($this->configuration['discount_enabled']) && $this->configuration['discount_enabled'] > 0 ? TRUE : FALSE;
        $discount_type = !empty($this->configuration['discount_type']) ? $this->configuration['discount_type'] : 'fixed';
        $discount_amount = !empty($this->configuration['discount_amount']) ? $this->configuration['discount_amount'] : '';
        $discount_amount_limit = !empty($this->configuration['discount_amount_limit']) ? $this->configuration['discount_amount_limit'] : '';
        $discount_percentage = !empty($this->configuration['discount_percentage']) ? $this->configuration['discount_percentage'] : '';

        $form['#attached']['library'][] = 'drupalcommerce_coinsnap/drupalcommerce_coinsnap_admin';
        
        $form['provider'] = [
            '#type' => 'select',
            '#title' => $this->t('Provider'),
            '#options' => [
                'coinsnap' => $this->t('Coinsnap'),
                'btcpay' => $this->t('BTCPay server'),
            ],
            '#default_value' => $provider,
            '#description' => $this->t('Choose provider: Coinsnap or BTCPay Server')
        ];
        
        $form['store_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Coinsnap Store ID'),
            '#default_value' => $store_id,
            '#description' => $this->t('Store ID from Coinsnap'),
            '#required' => TRUE,
            '#attributes' => [
                'data-provider' => 'coinsnap'
            ]
        ];

        // Password fields are never filled in: the stored key is not sent to the browser.
        $form['api_key'] = [
            '#type' => 'password',
            '#title' => $this->t('Coinsnap API Key'),
            '#description' => $this->keyHint($api_key, $this->t('API Key from Coinsnap')),
            '#attributes' => [
                'data-provider' => 'coinsnap'
            ]
        ];
        
        $form['btcpay_server_url'] = [
            '#type' => 'textfield',
            '#title' => $this->t('BTCPay server URL'),
            '#default_value' => $btcpay_server_url,
            '#description' => $this->t('Your BTCPay server URL'),
            '#required' => TRUE,
            '#attributes' => [
                'data-provider' => 'btcpay'
            ]
        ];

        $form['btcpay_store_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('BTCPay Store ID'),
            '#default_value' => $btcpay_store_id,
            '#description' => $this->t('Your BTCPay server Store ID'),
            '#required' => TRUE,
            '#attributes' => [
                'data-provider' => 'btcpay'
            ]
        ];

        $form['btcpay_api_key'] = [
            '#type' => 'password',
            '#title' => $this->t('BTCPay API Key'),
            '#description' => $this->keyHint($btcpay_api_key, $this->t('Your BTCPay server API Key')),
            '#attributes' => [
                'data-provider' => 'btcpay'
            ]
        ];

        $form['autoredirect'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Redirect after payment'),
            '#default_value' => $autoredirect, //   TRUE or FALSE
            '#description' => $this->t('Redirect to Thank You page after payment automatically')
        ];
        
        $form['returnurl'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Return URL after payment'),
            '#default_value' => $returnurl,
            '#description' => $this->t('Custom return URL after successful payment (default URL if blank)'),
        ];
        
        $form['discount_enabled'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Discount enabled'),
            '#default_value' => $discount_enabled, //   TRUE or FALSE
            '#description' => $this->t('Discount enabled')
        ];
        
        $form['discount_type'] = [
            '#type' => 'select',
            '#title' => $this->t('Discount type'),
            '#options' => [
                'fixed' => $this->t('Fixed'),
                'percentage' => $this->t('Percentage'),
            ],
            '#default_value' => $discount_type,
            '#description' => $this->t('Choose discount type: amount or percents'),
            '#attributes' => [
                'data-discount' => 'true'
            ]
        ];
        
        $form['discount_amount'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Discount amount'),
            '#default_value' => $discount_amount,
            '#description' => $this->t('Discount amount'),
            '#attributes' => [
                'data-discount' => 'true',
                'data-discount-type' => 'amount'
            ]
        ];
        
        $form['discount_amount_limit'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Max discount amount, %'),
            '#default_value' => $discount_amount_limit,
            '#description' => $this->t('Max discount amount for fixed discount, %'),
            '#attributes' => [
                'data-discount' => 'true',
                'data-discount-type' => 'amount'
            ]
        ];
        
        $form['discount_percentage'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Discount amount, %'),
            '#default_value' => $discount_percentage,
            '#description' => $this->t('Discount amount in percents (%)'),
            '#attributes' => [
                'data-discount' => 'true',
                'data-discount-type' => 'percentage'
            ]
        ];
        
        return $form;
    }


    

    private function keyHint(string $stored, $fallback) {
        return $stored === '' ? $fallback : $this->t('A key ending in @tail is saved. Leave empty to keep it.', ['@tail' => substr($stored, -4)]);
    }

    // Empty key fields keep the stored keys; a BTCPay key is never kept for a different server.
    private function keepStoredKeys(array $form, FormStateInterface $form_state): void {
        $parents = $form['#parents'];
        $values = $form_state->getValue($parents);
        if (trim((string) ($values['api_key'] ?? '')) === '') {
            $form_state->setValue(array_merge($parents, ['api_key']), (string) ($this->configuration['api_key'] ?? ''));
        }
        if (trim((string) ($values['btcpay_api_key'] ?? '')) === '') {
            $sameServer = rtrim(trim((string) ($values['btcpay_server_url'] ?? '')), '/') === rtrim((string) ($this->configuration['btcpay_server_url'] ?? ''), '/');
            $form_state->setValue(array_merge($parents, ['btcpay_api_key']), $sameServer ? (string) ($this->configuration['btcpay_api_key'] ?? '') : '');
        }
        $values = $form_state->getValue($parents);
        $field = ($values['provider'] ?? '') === 'btcpay' ? 'btcpay_api_key' : 'api_key';
        if (trim((string) ($values[$field] ?? '')) === '') {
            $form_state->setErrorByName($field, $this->t('Enter the API key.'));
            return;
        }
        if ($field === 'btcpay_api_key') {
            $problem = BtcpayKeyCheck::problem(trim((string) $values['btcpay_server_url']), trim((string) $values['btcpay_api_key']), trim((string) ($values['btcpay_store_id'] ?? '')));
            if ($problem !== null) {
                $form_state->setErrorByName('btcpay_api_key', $problem);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validateConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        parent::validateConfigurationForm($form, $form_state);
        $this->keepStoredKeys($form, $form_state);

        if (!$form_state->getErrors() && $form_state->isSubmitted()) {
            $values = $form_state->getValue($form['#parents']);
            
            $api_url = ($values['provider'] === 'btcpay')? $values['btcpay_server_url'] : COINSNAP_SERVER_URL;
            $api_key = ($values['provider'] === 'btcpay')? $values['btcpay_api_key'] : $values['api_key'];
            $store_id = ($values['provider'] === 'btcpay')? $values['btcpay_store_id'] : $values['store_id'];
            
            try {
                $webhookExists = $this->webhookExists($api_url, $api_key, $store_id, $values['provider']);
            } catch (\Throwable $e) {
                // An API error is not "no webhook": registering now would replace a working secret.
                $form_state->setErrorByName('api_key', $values['provider'] . ': the server could not be reached. Please check the Store ID and API key and try again.');
                return;
            }
            if (! $webhookExists) {
                    if ($webhook_data = $this->registerWebhook($api_url, $api_key, $store_id, $values['provider'])) {
                        if($values['provider'] === 'btcpay'){
                            $form_state->set('btcpay_webhook',json_encode($webhook_data));
                        }
                        else {
                            $form_state->set('webhook',json_encode($webhook_data));
                        }
                    }
                    else {
                        $errorMessage = $values['provider']. ": unable to Set Webhook on $api_url. Check Store ID and API Key";
                        $form_state->setErrorByName('api_key', $errorMessage);
                    }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        parent::submitConfigurationForm($form, $form_state);
        if (!$form_state->getErrors()) {
            $values = $form_state->getValue($form['#parents']);
            
            $this->configuration['provider'] = $values['provider'];
            $this->configuration['store_id'] = $values['store_id'];
            $this->configuration['api_key'] = $values['api_key'];
            $this->configuration['btcpay_server_url'] = $values['btcpay_server_url'];
            $this->configuration['btcpay_store_id'] = $values['btcpay_store_id'];
            $this->configuration['btcpay_api_key'] = $values['btcpay_api_key'];
            
            $this->configuration['autoredirect'] = $values['autoredirect'];
            $this->configuration['returnurl'] = $values['returnurl'];
            
            $this->configuration['discount_enabled'] = $values['discount_enabled'];
            $this->configuration['discount_type'] = $values['discount_type'];
            $this->configuration['discount_amount'] = $values['discount_amount'];
            $this->configuration['discount_amount_limit'] = $values['discount_amount_limit'];
            $this->configuration['discount_percentage'] = $values['discount_percentage'];
            
            if(!empty($form_state->get('webhook'))){
                $this->configuration['webhook'] = $form_state->get('webhook');
            }
            if(!empty($form_state->get('btcpay_webhook'))){
                $this->configuration['btcpay_webhook'] = $form_state->get('btcpay_webhook');
            }
            
            $api_url = ($values['provider'] === 'btcpay')? $values['btcpay_server_url'] : COINSNAP_SERVER_URL;
            $api_key = ($values['provider'] === 'btcpay')? $values['btcpay_api_key'] : $values['api_key'];
            $store_id = ($values['provider'] === 'btcpay')? $values['btcpay_store_id'] : $values['store_id'];
            
            $client = new \Coinsnap\Client\Invoice($api_url, $api_key);
            $store = new \Coinsnap\Client\Store($api_url, $api_key);
            
            $currentStore = \Drupal::service('commerce_store.current_store')->getStore();
            $currency_code = $currentStore->getDefaultCurrencyCode();
            
            $currency = ($currency_code !== null)? $currency_code : 'USD';

            $connectionData = '';

            if ($values['provider'] === 'btcpay') {

                    try {
                        $storePaymentMethods = $store->getStorePaymentMethods($store_id);

                        if ($storePaymentMethods['code'] === 200) {
                            if ($storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']) {
                                $checkInvoice = $client->checkPaymentData(0, $currency, 'bitcoin', 'calculation');
                            } elseif ($storePaymentMethods['result']['lightning']) {
                                $checkInvoice = $client->checkPaymentData(0, $currency, 'lightning', 'calculation');
                            }
                        }
                    } catch (\Exception $e) {
                         $connectionData = 'API connection is not established';
                    }

            } else {
                    $checkInvoice = $client->checkPaymentData(0, $currency, 'coinsnap', 'calculation');
            }

            if (isset($checkInvoice) && $checkInvoice['result']) {
                $serverType = ($values['provider'] === 'btcpay')? 'BTCPay server' : 'Coinsnap';
                $connectionData =  $serverType . ' is connected. Min order amount is' .' '. $checkInvoice['min_value'].' '.$currency;
            }
            else {
                    $connectionData = 'No payment method is configured';
            }
        }
        \Drupal::messenger()->addStatus($connectionData);
    }
	/**
     * {@inheritdoc}
     */
    public function onReturn(OrderInterface $order, Request $request){
        // The order is placed only once its latest invoice is paid or being paid; the webhook completes the payment.
        $recorded = $order->getData('coinsnap_invoices');
        $invoiceId = is_array($recorded) && $recorded !== [] ? (string) array_key_last($recorded) : '';
        if ($invoiceId !== '') {
            try {
                $invoice = $this->fetchInvoice($invoiceId);
            } catch (\Throwable $e) {
                // The API could not be asked: let the order through as pending; the webhook decides.
                $invoice = null;
            }
            if (is_array($invoice)) {
                $expected = $recorded[$invoiceId];
                $decision = InvoiceDecision::decide($invoice, $this->getProvider() === 'btcpay', (string) ($expected['amount'] ?? ''), (string) ($expected['currency'] ?? ''));
                if (!in_array($decision, [InvoiceDecision::PAID, InvoiceDecision::PROCESSING], true)) {
                    throw new PaymentGatewayException('The payment has not been received. Please complete the payment or choose another payment method.');
                }
            }
        }
        foreach ($this->paymentStorage()->loadByProperties(['order_id' => $order->id(), 'payment_gateway' => $this->entityId]) as $existing) {
            if ($invoiceId === '' || (string) $existing->getRemoteId() === $invoiceId || (string) $existing->getRemoteId() === '') {
                return;
            }
        }
        $this->paymentStorage()->create([
            'state' => 'pending',
            'amount' => $order->getBalance(),
            'payment_gateway' => $this->entityId,
            'order_id' => $order->id(),
            'remote_id' => $invoiceId,
            'remote_state' => 'pending',
        ])->save();
    }
	
    /**
     * Webhook endpoint. Only the invoice id is taken from the request; every decision comes from the API.
     */
    public function onNotify(Request $request){
        $rawPostData = (string) $request->getContent();
        $storedWebhook = json_decode((string) (($this->getProvider() === 'btcpay') ? $this->configuration['btcpay_webhook'] : $this->configuration['webhook']), true);
        $secret = is_array($storedWebhook) ? (string) ($storedWebhook['secret'] ?? '') : '';

        $problem = ($this->getProvider() === 'btcpay')
            ? Signature::btcpayProblem((string) $request->headers->get('BTCPay-Sig'), $rawPostData, $secret)
            : Signature::coinsnapProblem((string) $request->headers->get('X-Coinsnap-Signature'), $rawPostData, $secret, time());
        if ($problem !== null) {
            $this->logSecurity($problem);
            return new Response('Unauthorized', 401);
        }

        $body = json_decode($rawPostData, true);
        $invoiceId = (is_array($body) && is_string($body['invoiceId'] ?? null)) ? $body['invoiceId'] : '';
        if ($invoiceId === '') {
            return new Response('OK', 200);
        }
        $claimsPaid = in_array($body['type'] ?? '', ['Settled', 'InvoiceSettled'], true);

        try {
            $invoice = $this->fetchInvoice($invoiceId);
        } catch (\Coinsnap\Exception\RequestException $e) {
            if (in_array($e->getCode(), [400, 404], true)) {
                // The dashboard's "Send test webhook" button lands here.
                $this->logInfo('unknown invoice', $invoiceId);
                return new Response('OK', 200);
            }
            $this->logInfo('retry: API unavailable', $invoiceId);
            return new Response('Retry', 503);
        } catch (\Throwable $e) {
            $this->logInfo('retry: API unavailable', $invoiceId);
            return new Response('Retry', 503);
        }

        $metadata = is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [];
        $orderId = ($this->getProvider() === 'btcpay') ? ($metadata['orderId'] ?? '') : ($invoice['orderId'] ?? '');
        $order = (is_scalar($orderId) && ctype_digit((string) $orderId)) ? $this->loadOrder((int) $orderId) : null;
        if ($order === null || (string) $order->get('payment_gateway')->target_id !== (string) $this->entityId) {
            $this->logInfo('not ours', $invoiceId);
            return new Response('OK', 200);
        }

        $recorded = $order->getData('coinsnap_invoices');
        if (is_array($recorded) && !isset($recorded[$invoiceId])) {
            $this->logSecurity('invoice not created for this order', $invoiceId);
            return new Response('OK', 200);
        }
        // Orders placed before 1.2.0 have no record; check against the order total.
        $expected = is_array($recorded) ? $recorded[$invoiceId] : [
            'amount' => number_format(round((float) $order->getTotalPrice()->getNumber(), 2), 2, '.', ''),
            'currency' => $order->getTotalPrice()->getCurrencyCode(),
        ];

        $decision = InvoiceDecision::decide($invoice, $this->getProvider() === 'btcpay', (string) $expected['amount'], (string) $expected['currency']);
        if ($decision !== InvoiceDecision::PAID && ($claimsPaid || $decision === InvoiceDecision::MISMATCH)) {
            $this->logSecurity($claimsPaid ? 'forged status' : 'amount mismatch', $invoiceId);
            return new Response('OK', 200);
        }

        // The cart can change after checkout; an invoice for the old total must not pay the new one.
        if ($decision === InvoiceDecision::PAID && isset($expected['order_total']) && !$this->sameTotal($order, $expected)) {
            $this->logSecurity('order total changed', $invoiceId);
            return new Response('OK', 200);
        }

        $lock = 'coinsnap_order_' . $order->id();
        if (!$this->acquireLock($lock)) {
            return new Response('Retry', 503);
        }
        try {
            $this->applyDecision($decision, $order, $invoiceId, (string) ($invoice['status'] ?? ''));
        } finally {
            $this->releaseLock($lock);
        }
        return new Response('OK', 200);
    }

    // A completed payment is never changed; only the payment for this invoice moves forward.
    private function applyDecision(string $decision, $order, string $invoiceId, string $remoteState): void
    {
        if ($decision === InvoiceDecision::NONE) {
            $this->logInfo('no change', $invoiceId);
            return;
        }
        $payments = $this->paymentStorage()->loadByProperties(['order_id' => $order->id(), 'payment_gateway' => $this->entityId]);
        foreach ($payments as $existing) {
            if ($existing->getState()->getId() === 'completed' && $existing->getRemoteId() === $invoiceId) {
                $this->logInfo('already processed', $invoiceId);
                return;
            }
        }

        // Refunded or voided by the merchant: a later webhook never brings it back.
        $final = ['completed', 'partially_refunded', 'refunded', 'authorization_voided'];
        $payment = null;
        foreach ($payments as $existing) {
            $remoteId = (string) $existing->getRemoteId();
            if ($remoteId === $invoiceId && in_array($existing->getState()->getId(), $final, true)) {
                $this->logInfo('no change from ' . $existing->getState()->getId(), $invoiceId);
                return;
            }
        }
        foreach ($payments as $existing) {
            $remoteId = (string) $existing->getRemoteId();
            if (!in_array($existing->getState()->getId(), $final, true) && ($remoteId === $invoiceId || $remoteId === '')) {
                $payment = $existing;
                break;
            }
        }
        if ($payment === null && $decision === InvoiceDecision::EXPIRED) {
            $this->logInfo('no change', $invoiceId);
            return;
        }
        $payment ??= $this->paymentStorage()->create([
            'state' => 'pending',
            'amount' => $order->getTotalPrice(),
            'payment_gateway' => $this->entityId,
            'order_id' => $order->id(),
        ]);

        $payment->setRemoteId($invoiceId);
        $payment->setRemoteState($remoteState);
        if ($decision === InvoiceDecision::PAID) {
            $payment->setAmount($order->getTotalPrice());
            $payment->setState('completed');
            $this->logInfo('paid', $invoiceId);
        }
        elseif ($decision === InvoiceDecision::EXPIRED) {
            $payment->setState('voided');
            $this->logInfo('status expired', $invoiceId);
        }
        else {
            $this->logInfo('status processing', $invoiceId);
        }
        $payment->save();
    }

    private function sameTotal($order, array $expected): bool
    {
        $total = $order->getTotalPrice();
        return $total->getCurrencyCode() === (string) ($expected['order_currency'] ?? '')
            && InvoiceDecision::toMinorUnits((string) $total->getNumber()) === InvoiceDecision::toMinorUnits((string) $expected['order_total']);
    }

    protected function fetchInvoice(string $invoiceId): array
    {
        return (new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey()))->getInvoice($this->getStoreId(), $invoiceId)->getData();
    }

    protected function loadOrder(int $orderId)
    {
        return Order::load($orderId);
    }

    protected function paymentStorage()
    {
        return $this->entityTypeManager->getStorage('commerce_payment');
    }

    protected function acquireLock(string $name): bool
    {
        return \Drupal::lock()->acquire($name, 10.0);
    }

    protected function releaseLock(string $name): void
    {
        \Drupal::lock()->release($name);
    }

    protected function logInfo(string $reason, string $invoiceId = ''): void
    {
        \Drupal::logger('commerce_payment')->info($this->webhookLine('INFO', $reason, $invoiceId));
    }

    // At most 10 security lines a minute, so a flood of fake requests can't fill the log.
    protected function logSecurity(string $reason, string $invoiceId = ''): void
    {
        $key = 'coinsnap_webhook_seclog_' . gmdate('YmdHi');
        $cache = \Drupal::cache();
        $count = (int) ($cache->get($key)->data ?? 0) + 1;
        $cache->set($key, $count, time() + 120);
        if ($count === 11) {
            \Drupal::logger('commerce_payment')->warning('Coinsnap SECURITY webhook: more rejections this minute, not logged');
        }
        if ($count <= 10) {
            \Drupal::logger('commerce_payment')->warning($this->webhookLine('SECURITY', $reason, $invoiceId));
        }
    }

    private function webhookLine(string $level, string $reason, string $invoiceId): string
    {
        // The invoice id comes from the request.
        $id = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', $invoiceId), 0, 64);
        return sprintf('Coinsnap %s webhook reason=%s invoice=%s', $level, $reason, $id !== '' ? $id : '-');
    }

    private function apply_order_transition($order, $orderTransition){
        $order_state = $order->getState();
        $order_state_transitions = $order_state->getTransitions();
        if (!empty($order_state_transitions) && isset($order_state_transitions[$orderTransition])) {
            $order_state->applyTransition($order_state_transitions[$orderTransition]);
            $order->save();
        }
    }
    
    private function load_order($orderId){
        $order = Order::load($orderId);
        if (!$order) {
            \Drupal::logger('commerce_payment')->notice('Not found order with id @order_id.',['@order_id' => $orderId]);
            throw new BadRequestHttpException();
            return false;
        }
        return $order;
    }

    public function getWebhookUrl() {		        
        return Url::fromRoute('drupalcommerce_coinsnap.notify', [], ['absolute' => true])->toString();
    }
    
    public function getProvider(){
    	return (isset($this->configuration['provider']) && $this->configuration['provider'] === 'btcpay')? 'btcpay' : 'coinsnap';
    }

    public function getStoreId(){
    	return ($this -> getProvider() === 'btcpay')? $this->configuration['btcpay_store_id'] : $this->configuration['store_id'];
    }
  
    public function getApiKey() {
        return ($this -> getProvider() === 'btcpay')? $this->configuration['btcpay_api_key'] : $this->configuration['api_key'];
    }

    public function getApiUrl() {
        return ($this -> getProvider() === 'btcpay')? $this->configuration['btcpay_server_url'] : COINSNAP_SERVER_URL;
    }
    
    public function getReturnUrl() {
        return $this -> configuration['returnurl'];
    }
    
    public function getAutoredirect() {
        return $this -> configuration['autoredirect'];
    }
    
    public function getDiscount(){
        return [
            'discount_enabled' => $this->configuration['discount_enabled'],
            'discount_type' => $this->configuration['discount_type'],
            'discount_amount' => $this->configuration['discount_amount'],
            'discount_amount_limit' => $this->configuration['discount_amount_limit'],
            'discount_percentage' => $this->configuration['discount_percentage']
        ];
    }

    // Throws when the API can't be asked; the caller must not treat that as "no webhook".
    function webhookExists(string $apiUrl, string $apiKey, string $storeId, string $provider): bool {
        $whClient = new \Coinsnap\Client\Webhook($apiUrl, $apiKey);
        $stored = json_decode((string) (($provider === 'btcpay') ? $this->configuration['btcpay_webhook'] : $this->configuration['webhook']), true);

        if (is_array($stored) && !empty($stored['id'])) {
            try {
                $existing = $whClient->getWebhook($storeId, (string) $stored['id']);
                if (strpos((string) ($existing->getData()['url'] ?? ''), $this->getWebhookUrl()) !== false) {
                    return true;
                }
            } catch (\Coinsnap\Exception\RequestException $e) {
                if (!in_array($e->getCode(), [400, 404], true)) {
                    throw $e;
                }
            }
        }
        // Remove webhooks of ours whose secret this configuration does not hold.
        foreach ($whClient->getWebhooks($storeId) as $webhook) {
            if (strpos((string) $webhook->getData()['url'], $this->getWebhookUrl()) !== false) {
                try {
                    $whClient->deleteWebhook($storeId, $webhook->getData()['id']);
                } catch (\Throwable $e) {
                    \Drupal::logger('commerce_payment')->warning('Coinsnap: stale webhook could not be deleted');
                }
            }
        }
        return false;
    }

    public function registerWebhook(string $apiUrl, string $apiKey, string $storeId, string $provider = 'coinsnap'){
        try {
            $whClient = new \Coinsnap\Client\Webhook($apiUrl, $apiKey);
            $webhook_events = ($provider === 'btcpay')? self::BTCPAY_WEBHOOK_EVENTS : self::COINSNAP_WEBHOOK_EVENTS;
            $webhook = $whClient->createWebhook($storeId, $this->getWebhookUrl(), $webhook_events, null)->getData();
        }
        catch (\Throwable $e) {
            \Drupal::logger('commerce_payment')->error('Coinsnap: webhook could not be registered (status @status)', ['@status' => (int) $e->getCode()]);
            return false;
        }
        if (empty($webhook['id']) || empty($webhook['secret'])) {
            return false;
        }
        return ['id' => $webhook['id'], 'secret' => $webhook['secret'], 'url' => $webhook['url'] ?? $this->getWebhookUrl()];
    }
}
