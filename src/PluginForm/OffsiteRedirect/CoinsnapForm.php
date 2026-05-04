<?php
namespace Drupal\drupalcommerce_coinsnap\PluginForm\OffsiteRedirect;

require_once __DIR__ . '/../../Coinsnap/library/loader.php';

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class CoinsnapForm extends PaymentOffsiteForm {

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state){
		
        $form = parent::buildConfigurationForm($form, $form_state);        
        $payment = $this->entity;
        $paymentGatewayPlugin = $payment->getPaymentGateway()->getPlugin();				
        $order = $payment->getOrder();						
	$order_id = $payment->getOrderID();
        $address = $order->getBillingProfile()->get('address')->first();
        
        $amount = round($payment->getAmount()->getNumber(), 8);
        $buyerEmail = $order->getEmail();
	$buyerName =  $address->getGivenName().' '.$address->getFamilyName();
	$currency = $payment->getAmount()->getCurrencyCode();
                
        $client = new \Coinsnap\Client\Invoice($paymentGatewayPlugin->getApiUrl(), $paymentGatewayPlugin->getApiKey());
        $checkInvoice = $this->checkAmount($amount, strtoupper($currency));
        
        if ($checkInvoice['result'] === true) {
            
            $redirectUrl = (!empty($paymentGatewayPlugin->getReturnUrl()))? $paymentGatewayPlugin->getReturnUrl() : $form['#return_url'];
            $metadata = [];
            $metadata['orderNumber'] = $order_id;
            $metadata['customerName'] = $buyerName;

            if ($paymentGatewayPlugin->getProvider() === 'btcpay') {
                $metadata['orderId'] = $order_id;
            }

            $redirectAutomatically = ($paymentGatewayPlugin->getAutoredirect() > 0) ? true : false;
            $walletMessage = '';

            // Handle currencies non-supported by BTCPay Server, we need to change them BTC and adjust the amount.
            if ($currency !== 'BTC' && $paymentGatewayPlugin->getProvider() === 'btcpay') {
                $store = new \Coinsnap\Client\Store($paymentGatewayPlugin->getApiUrl(), $paymentGatewayPlugin->getApiKey());
                $btcpayCurrencies = $store -> getStoreCurrenciesRates($paymentGatewayPlugin->getStoreId(),array($currency));
                $isCurrency = true;
                if(!isset($btcpayCurrencies['result']['error']) && count($btcpayCurrencies['result']['currencies'])>0){
                    if(!isset($btcpayCurrencies['result']['currencies']['BTC_'.$currency])){
                        $isCurrency = false;
                    }
                }
                else {
                    $isCurrency = false;
                }
                    
                // Handle currencies non-supported by BTCPay Server, we need to change them BTC and adjust the amount.
                if( !$isCurrency ){
                    $currency = 'BTC';
                    $rate = 1/$checkInvoice['rate'];
                    $amountBTC = bcdiv(strval($amount), strval($rate), 8);
                    $amount = (float)$amountBTC;
                }
            }
        
            $camount = ($currency === 'BTC')? \Coinsnap\Util\PreciseNumber::parseFloat($amount,8) : \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);
            
            $invoice = $client->createInvoice(
                $paymentGatewayPlugin->getStoreId(),  
                $currency,
		$camount,
		$order_id,
		$buyerEmail,
		$buyerName, 
		$redirectUrl,
		COINSNAP_DRUPAL_REFERRAL_CODE,     
		$metadata,
                $redirectAutomatically,
                $walletMessage
            );
		
            $payurl = $invoice->getData()['checkoutLink'] ;		
		
            if ($payurl) {	
                $rData = array();
                return $this->buildRedirectForm($form, $form_state, $payurl, $rData, PaymentOffsiteForm::REDIRECT_GET);			
            }
            else {			
                $errorMessage = 'Invoice request error';
                \Drupal::logger('commerce_payment')->error('Invoice request error: '.$e->getMessage());
                throw new PaymentGatewayException('Invoice request error: '.$errorMessage);
            }
        }
        else {

            if ($checkInvoice['error'] === 'currencyError') {
                $errorMessage = 'Currency '.strtoupper($currency).' is not supported by Coinsnap';
            } elseif ($checkInvoice['error'] === 'amountError') {
                $errorMessage = 'Invoice amount cannot be less than '.$checkInvoice['min_value'].' '.strtoupper($currency);
            } else {
                $errorMessage = $checkInvoice['error'];
            }
            \Drupal::logger('commerce_payment')->error('Invoice request error: '.$errorMessage);
            throw new PaymentGatewayException('Invoice request error: '.$errorMessage);
        }
    }
	
    public function checkAmount($amount, $currency){
        
        $payment = $this->entity;
        $paymentGatewayPlugin = $payment->getPaymentGateway()->getPlugin();
        
        $client = new \Coinsnap\Client\Invoice($paymentGatewayPlugin->getApiUrl(), $paymentGatewayPlugin->getApiKey());
        $store = new \Coinsnap\Client\Store($paymentGatewayPlugin->getApiUrl(), $paymentGatewayPlugin->getApiKey());
        $checkInvoice = [];

        try {
            if ($paymentGatewayPlugin->getProvider() === 'btcpay') {
                try {
                    $storePaymentMethods = $store->getStorePaymentMethods($paymentGatewayPlugin->getStoreId());
                    
                    \Drupal::logger('commerce_payment')->notice('Store Payment Methods: '.print_r($storePaymentMethods,true));
                    
                    if ($storePaymentMethods['code'] === 200 && !isset($storePaymentMethods['result']['error'])){
                        if (!$storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']) {
                            $errorMessage = 'No payment method is configured on BTCPay server';
                            $checkInvoice = array('result' => false,'error' => $errorMessage);
                        }
                    }
                    else {
                        $errorMessage = 'Error store loading. Wrong or empty Store ID';
                        $checkInvoice = array('result' => false,'error' => $errorMessage);
                    }

                    if( isset($storePaymentMethods['result']['error']) ){
                        $errorMessage = 'Error data handling ('.$storePaymentMethods['result']['error'].')';
                        $checkInvoice = array('result' => false,'error' => $errorMessage);
                    }
                    elseif ($storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']) {
                        $checkInvoice = $client->checkPaymentData((float)$amount, strtoupper($currency), 'bitcoin');
                    } elseif ($storePaymentMethods['result']['lightning']) {
                        $checkInvoice = $client->checkPaymentData((float)$amount, strtoupper($currency), 'lightning');
                    }
                } catch (\Throwable $e) {
                    $errorMessage = 'API connection is not established';
                    $checkInvoice = array('result' => false,'error' => $errorMessage);
                }
            } else {
                $checkInvoice = $client->checkPaymentData((float)$amount, strtoupper($currency));
            }
        } catch (\Throwable $e) {
            $errorMessage = 'API connection is not established';
            $checkInvoice = array('result' => false,'error' => $errorMessage);
        }
        return $checkInvoice;
    }
}
