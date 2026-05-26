<?php
namespace Drupal\drupalcommerce_coinsnap\Plugin\Commerce\OrderProcessor;

use Drupal\commerce_order\OrderProcessorInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_order\Adjustment;

class PaymentDiscountProcessor implements OrderProcessorInterface {

    public function process(OrderInterface $order) {
        
        $payment_gateway = $order->get('field_payment_gateway')->value;
        
        \Drupal::logger('test')->notice('Discount processor for '.$payment_gateway.' is loaded.');

        foreach ($order->getAdjustments() as $adjustment) {
            if ($adjustment->getLabel() === 'Bitcoin discount') {
                $order->removeAdjustment($adjustment);
            }
        }
        
        if($payment_gateway === 'bitcoin_lightning'){
            
            $gateway = \Drupal::entityTypeManager()->getStorage('commerce_payment_gateway')->load($payment_gateway);
            $paymentGatewayPlugin = $gateway->getPlugin();
            
            $discountSettings = $paymentGatewayPlugin->getDiscount();
            
            $discount_enabled = $discountSettings['discount_enabled'];
            $isDiscount = false;
            $order_total = floatval($order->getTotalPrice()->getNumber());
            
            if ($discount_enabled){
                $discount_type = $discountSettings['discount_type'];
                $discount_amount = ($discountSettings['discount_amount'] > 0)? $discountSettings['discount_amount'] : 0;
                $discount_percentage = (floatval($discountSettings['discount_percentage']) >0 )? $discountSettings['discount_percentage'] : 0;
                
                if($discount_type === 'fixed' && floatval($discount_amount) > 0){
                
                    $discount_amount = round(floatval($discount_amount),2);
                    $discount_amount_limit = (floatval($discountSettings['discount_amount_limit']) > 0)? floatval($discountSettings['discount_amount_limit']) : 0;

                    if($discount_amount_limit >= 0 && $discount_amount_limit < 100){
                        if($discount_amount > ($order_total * $discount_amount_limit / 100)){
                            $discount_amount = round($order_total * $discount_amount_limit / 100,2);
                        }

                        if($discount_amount < $order_total){
                            $isDiscount = true;
                            $discount_title = '';
                        }
                    }
                }
                elseif($discount_type === 'percentage' && $discount_percentage > 0) {
                    if($discount_percentage > 0 && $discount_percentage < 100){
                        $isDiscount = true;
                        $discount_title = ' '.$discount_percentage . '%';
                        $discount_amount = round($order_total * $discount_percentage / 100,2);
                    }
                }

                if($isDiscount && $discount_amount > 0){
                    $total_discount_amount = new Price($discount_amount, $order->getTotalPrice()->getCurrencyCode());
                    $order->addAdjustment(new Adjustment([
                        'type' => 'promotion',
                        'label' => 'Bitcoin discount',
                        'amount' => $total_discount_amount->multiply('-1'),
                    ]));
                }
            }
        }
    }
}