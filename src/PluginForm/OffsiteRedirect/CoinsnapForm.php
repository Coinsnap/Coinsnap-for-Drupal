<?php

namespace Drupal\drupalcommerce_coinsnap\PluginForm\OffsiteRedirect;

require_once __DIR__ . '/../../Coinsnap/library/autoload.php';


use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class CoinsnapForm extends PaymentOffsiteForm
{

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
		
        $form = parent::buildConfigurationForm($form, $form_state);        
        $payment = $this->entity;
        $paymentGatewayPlugin = $payment->getPaymentGateway()->getPlugin();				
        $order = $payment->getOrder();						
		$amount = $payment->getAmount()->getNumber();
		$return_url = $form['#return_url'];
		$address = $order->getBillingProfile()->get('address')->first();
		$webhook_url = $paymentGatewayPlugin->get_webhook_url();
		

		if (! $paymentGatewayPlugin->webhookExists($paymentGatewayPlugin->getStoreId(), $paymentGatewayPlugin->getApiKey(), $webhook_url)){
            if (! $paymentGatewayPlugin->registerWebhook($paymentGatewayPlugin->getStoreId(), $paymentGatewayPlugin->getApiKey(),$webhook_url)) { 
				echo "unable to set Webhook url";
				exit;                
            }
         }   
		
		$currency = $payment->getAmount()->getCurrencyCode();
		
		$invoice_no = $payment->getOrderID();		
		
		$checkoutOptions = new \Coinsnap\Client\InvoiceCheckoutOptions();
        $checkoutOptions->setRedirectURL( $return_url );
        $client =new \Coinsnap\Client\Invoice($paymentGatewayPlugin->getApiUrl(), $paymentGatewayPlugin->getApiKey());
        $camount = \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);
		$buyerEmail = $order->getEmail();
		$buyerName = $address->getGivenName().' '.$address->getFamilyName();

		$metadata = [];
        $metadata['orderNumber'] = $invoice_no;
        $metadata['customerName'] = $buyerName;

		$csinvoice = $client->createInvoice(
			$paymentGatewayPlugin->getStoreId(),  
			strtoupper( $currency ),
			$camount,
			$invoice_no,
			$buyerEmail,
			$buyerName, 
			$return_url,
			'',     
			$metadata,
			$checkoutOptions
		);
		$payurl = $csinvoice->getData()['checkoutLink'] ;		
		if (empty($payurl)){
			echo "API Error";
			exit;
		}
		$rData = array();
        return $this->buildRedirectForm($form, $form_state, $payurl, $rData, PaymentOffsiteForm::REDIRECT_GET);
    }
	
	 
}
