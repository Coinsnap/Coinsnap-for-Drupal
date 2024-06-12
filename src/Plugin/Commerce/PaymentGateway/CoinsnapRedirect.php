<?php
namespace Drupal\drupalcommerce_coinsnap\Plugin\Commerce\PaymentGateway;
require_once __DIR__ . '/../../../Coinsnap/library/autoload.php';

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

class CoinsnapRedirect extends OffsitePaymentGatewayBase
{
	public const WEBHOOK_EVENTS = ['New','Expired','Settled','Processing'];	 
	/**
     * {@inheritdoc}
     */    
    public function defaultConfiguration()
    {
        return [
                'store_id' => '',
                'api_key' => '',
            ] + parent::defaultConfiguration();
    }
	
    
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        $form = parent::buildConfigurationForm($form, $form_state);
		unset($form['mode']);

        $store_id = !empty($this->configuration['store_id']) ? $this->configuration['store_id'] : '';
        $api_key = !empty($this->configuration['api_key']) ? $this->configuration['api_key'] : '';

        
        
        $form['store_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Trade ID'),
            '#default_value' => $store_id,
            '#description' => $this->t('Store ID from Coinsnap.'),
            '#required' => TRUE
        ];

        $form['api_key'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Token'),
            '#default_value' => $api_key,
            '#description' => $this->t('API Key from Coinsnap'),
            '#required' => TRUE
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
            $this->configuration['store_id'] = $values['store_id'];
            $this->configuration['api_key'] = $values['api_key'];
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
            $this->configuration['store_id'] = $values['store_id'];
            $this->configuration['api_key'] = $values['api_key'];
        }
    }
	/**
     * {@inheritdoc}
     */
    public function onReturn(OrderInterface $order, Request $request)
    {
      
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
    public function onNotify(Request $request)
    {
		$notify_json = file_get_contents('php://input');          
        $notify_ar = json_decode($notify_json, true);
        $invoice_id = $notify_ar['invoiceId'];
    
        try {
            $client = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getApiKey() );			
            $csinvoice = $client->getInvoice($this->getStoreId(), $invoice_id);
            $payment_status = $csinvoice->getData()['status'] ;
            $order_id = $csinvoice->getData()['orderId'] ;				
            
        }catch (\Throwable $e) {													
                echo "Error";
                 exit;
        }
        $payment_res = $csinvoice->getData();        

		
		
        if (!isset($order_id)) {
            \Drupal::messenger()->addMessage($this->t('Site can not get info from you transaction. Please return to store and perform the order'),
                'success');
            $response = new RedirectResponse('/', 302);
            $response->send();
            return;
        }
        
        
        $order = Order::load($order_id);        
        $newstatus = 'F';		
		if ($payment_status == 'Processing') $newstatus = 'P';
		if ($payment_status == 'Settled') $newstatus = 'P';
		if ($payment_status == 'Expired') $newstatus = 'F';		
        
        $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
        $transactionArray = $paymentStorage->loadByProperties(['order_id' => $order->id()]);
		
		
        if (!empty($transactionArray)) {
            $transaction = array_shift($transactionArray);
        } else {
			
            $transaction = $paymentStorage->create([
                'payment_gateway' => $this->entityId,
                'order_id' => $order->id(),
                'remote_id' => $invoice_id
            ]);		
        }
		$transaction->setRemoteState($payment_status);

        if ($newstatus == 'P'){            
            $transaction->setState('completed');    
        }
        else {            
            $transaction->setState('voided');    
        }
        $transaction->setAmount($order->getTotalPrice());
        $paymentStorage->save($transaction);
        echo "OK";
    }
	
	
	
	
	
	
	private function apply_order_transition($order, $orderTransition)
    {
        $order_state = $order->getState();
        $order_state_transitions = $order_state->getTransitions();
        if (!empty($order_state_transitions) && isset($order_state_transitions[$orderTransition])) {
            $order_state->applyTransition($order_state_transitions[$orderTransition]);
            $order->save();
        }
    }
    private function load_order($orderId)
    {
        $order = Order::load($orderId);
        if (!$order) {
            $this->logger->warning(
                'Not found order with id @order_id.',
                ['@order_id' => $orderId]
            );
            throw new BadRequestHttpException();
            return false;
        }
        return $order;
    }

    public function get_webhook_url() {		        
        return Url::fromRoute('drupalcommerce_coinsnap.notify', [], ['absolute' => true])->toString();
    }

    public function getStoreId() {
    	return $this->configuration['store_id'];
  	}
  
   	public function getApiKey() {
	   return $this->configuration['api_key'];
  	}

    public function getApiUrl() {
        return 'https://app.coinsnap.io';
    }	

    public function webhookExists(string $storeId, string $apiKey, string $webhook): bool {	
        try {		
            $whClient = new \Coinsnap\Client\Webhook( $this->getApiUrl(), $apiKey );		
            $Webhooks = $whClient->getWebhooks( $storeId );
            
			
            
            foreach ($Webhooks as $Webhook){					
                //self::deleteWebhook($storeId,$apiKey, $Webhook->getData()['id']);
                if ($Webhook->getData()['url'] == $webhook) return true;	
            }
        }catch (\Throwable $e) {			
            return false;
        }
    
        return false;
    }
    public  function registerWebhook(string $storeId, string $apiKey, string $webhook): bool {	
        try {			
            $whClient = new \Coinsnap\Client\Webhook($this->getApiUrl(), $apiKey);
            
            $webhook = $whClient->createWebhook(
                $storeId,   //$storeId
                $webhook, //$url
                self::WEBHOOK_EVENTS,   
                null    //$secret
            );		
            
            return true;
        } catch (\Throwable $e) {
            return false;	
        }

        return false;
    }

    public function deleteWebhook(string $storeId, string $apiKey, string $webhookid): bool {	    
        
        try {			
            $whClient = new \Coinsnap\Client\Webhook($this->getApiUrl(), $apiKey);
            
            $webhook = $whClient->deleteWebhook(
                $storeId,   //$storeId
                $webhookid, //$url			
            );					
            return true;
        } catch (\Throwable $e) {
            
            return false;	
        }
    }   
}
