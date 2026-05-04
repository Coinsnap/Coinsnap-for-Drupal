<?php
namespace Drupal\drupalcommerce_coinsnap\Plugin\Commerce\PaymentGateway;
require_once __DIR__ . '/../../../Coinsnap/library/loader.php';

if(!defined('COINSNAP_DRUPAL_VERSION')){ define( 'COINSNAP_DRUPAL_VERSION', '1.1.0' ); }
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
            'discount_type' => 'amount',
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
        $discount_type = !empty($this->configuration['discount_type']) ? $this->configuration['discount_type'] : 'amount';
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

        $form['api_key'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Coinsnap API Key'),
            '#default_value' => $api_key,
            '#description' => $this->t('API Key from Coinsnap'),
            '#required' => TRUE,
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
            '#type' => 'textfield',
            '#title' => $this->t('BTCPay API Key'),
            '#default_value' => $btcpay_api_key,
            '#description' => $this->t('Your BTCPay server API Key'),
            '#required' => TRUE,
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


    

    /**
     * {@inheritdoc}
     */
    public function validateConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        parent::validateConfigurationForm($form, $form_state);

        if (!$form_state->getErrors() && $form_state->isSubmitted()) {
            $values = $form_state->getValue($form['#parents']);
            
            $api_url = ($values['provider'] === 'btcpay')? $values['btcpay_server_url'] : COINSNAP_SERVER_URL;
            $api_key = ($values['provider'] === 'btcpay')? $values['btcpay_api_key'] : $values['api_key'];
            $store_id = ($values['provider'] === 'btcpay')? $values['btcpay_store_id'] : $values['store_id'];
            
            if (! $this->webhookExists($api_url, $api_key, $store_id, $values['provider'])) {
                    if ($webhook_data = $this->registerWebhook($api_url, $api_key, $store_id, $values['provider'])) {
                        \Drupal::logger('commerce_payment')->notice('Webhook: '.print_r($webhook_data,true));
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
        $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    	$payment = $payment_storage->create([
      		'state' => 'pending',
      		'amount' => $order->getBalance(),
      		'payment_gateway' => $this->entityId,
      		'order_id' => $order->id(),      
      		'remote_state' => 'pending',
    	]);
    	$payment->save();
    }
	
	/**
     * Notity payment callback
     * @param Request $request
     * @return null|\Symfony\Component\HttpFoundation\Response|void
     */
    public function onNotify(Request $request){
        
        // First check if we have any input
        $rawPostData = file_get_contents('php://input');
        
        if (!$rawPostData) {
            http_response_code(400);
            die('No raw post data received');
        } else {
            \Drupal::logger('commerce_payment')->notice('Coinsnap Webhook Payload: '.$rawPostData);
        }
        
        // Get headers and check for signature
        $headers = getallheaders();
        $signature = null;
        $payloadKey = null;
        $_provider = ($this -> getProvider() === 'btcpay') ? 'btcpay' : 'coinsnap';
        
        \Drupal::logger('commerce_payment')->notice('Coinsnap Webhook Payload headers: '.print_r($headers,true));
        
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'x-coinsnap-sig' || strtolower($key) === 'btcpay-sig') {
                $signature = $value;
                $payloadKey = strtolower($key);
            }
        }

        // Handle missing or invalid signature
        if (!isset($signature)) {
            http_response_code(401);
            die('Authentication required');
        }

        // Validate the signature
        $storedWebhook = ($_provider === 'btcpay')? $this->configuration['btcpay_webhook'] : $this->configuration['webhook'];
        $webhook = json_decode($storedWebhook,true,512,JSON_INVALID_UTF8_IGNORE);
        
        if (!\Coinsnap\Client\Webhook::isIncomingWebhookRequestValid($rawPostData, $signature, $webhook['secret'])) {
            http_response_code(401);
            die('Invalid authentication signature for '.$payloadKey);
        }
        try {

            // Parse the JSON payload
            $postData = json_decode($rawPostData, false, 512, JSON_INVALID_UTF8_IGNORE);
            
            print_r($postData);

            if (!isset($postData->invoiceId)) {
                http_response_code(400);
                die('No Coinsnap invoiceId provided');
            }

            if (strpos($postData->invoiceId, 'test_') !== false) {
                \Drupal::logger('commerce_payment')->notice('Successful webhook test.');
                http_response_code(200);
                die('Successful webhook test');
            }

            $invoice_id = $postData->invoiceId;

            try {
                $client = new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
                $csinvoice = $client->getInvoice($this->getStoreId(), $invoice_id);
                $status = $csinvoice->getData()['status'] ;
                $order_id = ($_provider === 'btcpay') ? $csinvoice->getData()['metadata']['orderId'] : $csinvoice->getData()['orderId'];
                
                if(empty($order_id)){
                    \Drupal::logger('commerce_payment')->error('Cannot find order from transaction');
                    $response = new RedirectResponse('/', 302);
                    $response->send();
                    return;
                }
                                
                \Drupal::logger('commerce_payment')->notice('Coinsnap Webhook Payload Order Id: '.$order_id.', Status: '.$status);
                
                $order = Order::load($order_id);        
                $newstatus = 'F';		
                if ($status === 'Processing'){ $newstatus = 'P'; }
                if ($status === 'Settled'){ $newstatus = 'P'; }
                if ($status === 'Expired'){ $newstatus = 'F'; }
        
                $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
                $transactionArray = $paymentStorage->loadByProperties(['order_id' => $order->id()]);
                
                if (!empty($transactionArray)) {
                    $transaction = array_shift($transactionArray);
                }
                else {
                    $transaction = $paymentStorage->create([
                        'payment_gateway' => $this->entityId,
                        'order_id' => $order->id(),
                        'remote_id' => $invoice_id
                    ]);		
                }
		$transaction->setRemoteState($status);

                if ($newstatus === 'P'){            
                    $transaction->setState('completed');    
                }
                else {            
                    $transaction->setState('voided');    
                }
                $transaction->setAmount($order->getTotalPrice());
                $paymentStorage->save($transaction);
                
                echo "OK";
                exit;
            } catch (JsonException $e) {
                \Drupal::logger('commerce_payment')->error('Coinsnap Webhook Payload Error: '.$e->getMessage());
                http_response_code(400);
                die('Invalid JSON payload');
            }
        } catch (\Throwable $e) {
            http_response_code(500);
            die('Internal server error');
        }
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

    function webhookExists(string $apiUrl, string $apiKey, string $storeId, string $provider): bool {	
        
        $whClient = new \Coinsnap\Client\Webhook($apiUrl, $apiKey);
        $storedWebhook = ($provider === 'btcpay')? $this->configuration['btcpay_webhook'] : $this->configuration['webhook'];
                
        if ($storedWebhook !== null && !empty($storedWebhook) && is_array(json_decode($storedWebhook,true,512,JSON_INVALID_UTF8_IGNORE))) {
            
            try {
		$existingWebhook = $whClient->getWebhook( $storeId, $storedWebhook['id'] );
                
                if($existingWebhook->getData()['secret'] === $storedWebhook['secret'] && strpos( $existingWebhook->getData()['url'], $this -> getWebhookUrl() ) !== false){
                    return true;
		}
            }
            catch (\Throwable $e) {
		echo "Webhook check error: ".$e->getMessage();
            }
	}
        try {
            $storeWebhooks = $whClient->getWebhooks( $storeId );
            foreach($storeWebhooks as $webhook){
                if(strpos( $webhook->getData()['url'], $this -> getWebhookUrl() ) !== false){
                    $whClient->deleteWebhook( $storeId, $webhook->getData()['id'] );
                }
            }
        }
        catch (\Throwable $e) {
            echo "Webhook deletion error: ".$e->getMessage();
        }
        
	return false;
    }
    
    public function registerWebhook(string $apiUrl, string $apiKey, string $storeId, string $provider = 'coinsnap'){
        
        try {
            $whClient = new \Coinsnap\Client\Webhook( $apiUrl, $apiKey );
            $webhook_events = ($provider === 'btcpay')? self::BTCPAY_WEBHOOK_EVENTS : self::COINSNAP_WEBHOOK_EVENTS;
            $webhook = $whClient->createWebhook(
                $storeId,   //$storeId
		$this -> getWebhookUrl(), //$url
		$webhook_events,   //$specificEvents
		null    //$secret
            );
            
            $webhook_data = [
                    'id' => $webhook->getData()['id'],
                    'secret' => $webhook->getData()['secret'],
                    'url' => $webhook->getData()['url']
            ];
            
            return $webhook_data;
	}
        catch (\Throwable $e) {
            echo "Webhook creation error: ".$e->getMessage();
	}
        
        return false;
    }  
}
