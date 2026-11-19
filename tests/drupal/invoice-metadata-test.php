<?php
// Builds a real order (two items, a coupon) and the real CoinsnapForm for each provider, and checks the invoice
// request a fake API received: top-level orderId (what onNotify() matches on) and the order/customer metadata.
//   php -S 127.0.0.1:8099 modules/custom/drupalcommerce_coinsnap/tests/drupal/fake-invoice-api.php &
//   # settings.php: define("COINSNAP_SERVER_URL", "http://127.0.0.1:8099");
//   drush php:script modules/custom/drupalcommerce_coinsnap/tests/drupal/invoice-metadata-test.php
// Needs commerce_product, commerce_checkout, commerce_payment and commerce_promotion. Test site only: it creates entities.
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_promotion\Entity\Coupon;
use Drupal\commerce_promotion\Entity\Promotion;
use Drupal\commerce_store\Entity\Store;
use Drupal\Core\Form\FormState;
use Drupal\profile\Entity\Profile;

define('COINSNAP_CURRENCY_RATES', ['result' => true, 'data' => ['eur' => ['value' => 90000], 'btc' => ['value' => 1]]]);
$passed = 0; $failed = 0;
$check = function (string $name, bool $ok, $detail = null) use (&$passed, &$failed) {
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === null ? '' : ' -- got ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
};

\Drupal::service('commerce_price.currency_importer')->import('EUR');
$store = Store::create(['type' => 'online', 'uid' => 1, 'name' => 'Meta Test Store', 'mail' => 'store@example.com',
    'address' => ['country_code' => 'DE', 'locality' => 'Berlin', 'postal_code' => '10115', 'address_line1' => 'X 1'],
    'default_currency' => 'EUR', 'billing_countries' => ['DE']]);
$store->save();
$variations = [];
foreach ([['MUG-1', 'Tasse', '19.50'], ['TS-2', 'T-Shirt', '70.00']] as [$sku, $title, $price]) {
    $v = ProductVariation::create(['type' => 'default', 'sku' => $sku . '-' . substr(md5(microtime()), 0, 4), 'title' => $title, 'price' => new Price($price, 'EUR'), 'status' => 1]);
    $v->save();
    Product::create(['type' => 'default', 'title' => $title, 'stores' => [$store], 'variations' => [$v]])->save();
    $variations[] = $v;
}
$promotion = Promotion::create(['name' => 'Five off', 'order_types' => ['default'], 'stores' => [$store->id()], 'status' => TRUE,
    'offer' => ['target_plugin_id' => 'order_fixed_amount_off', 'target_plugin_configuration' => ['amount' => ['number' => '5.00', 'currency_code' => 'EUR']]]]);
$promotion->save();
$coupon = Coupon::create(['code' => 'SUMMER5-' . substr(md5(microtime()), 0, 4), 'promotion_id' => $promotion->id(), 'status' => TRUE]);
$coupon->save();

foreach (['coinsnap', 'btcpay'] as $provider) {
    $gw = PaymentGateway::create(['id' => 'meta_' . $provider . '_' . substr(md5(microtime()), 0, 4), 'label' => 'Bitcoin', 'plugin' => 'coinsnap',
        'configuration' => [
            'provider' => $provider, 'store_id' => 'CS-STORE-1', 'api_key' => 'k1', 'btcpay_server_url' => 'http://127.0.0.1:8099',
            'btcpay_store_id' => 'BTC-STORE-1', 'btcpay_api_key' => 'k2', 'autoredirect' => 1, 'returnurl' => '', 'webhook_base_url' => 'https://shop.example', 'discount_enabled' => 0,
            'discount_type' => 'fixed', 'discount_amount' => 0, 'discount_amount_limit' => 0, 'discount_percentage' => 0, 'mode' => 'live',
        ]]);
    $gw->save();

    $profile = Profile::create(['type' => 'customer', 'uid' => 0, 'address' => [
        'country_code' => 'DE', 'given_name' => 'Erika', 'family_name' => 'Mustermann', 'organization' => 'Muster & Co',
        'address_line1' => 'Hauptstr. 5', 'address_line2' => 'Hinterhaus', 'locality' => 'Berlin', 'postal_code' => '10115']]);
    $profile->save();
    $items = [];
    foreach ($variations as $i => $v) {
        $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $v, 'quantity' => $i === 0 ? 2 : 1, 'unit_price' => $v->getPrice(), 'title' => $v->getTitle()]);
        $item->save();
        $items[] = $item;
    }
    $order = Order::create(['type' => 'default', 'store_id' => $store->id(), 'uid' => 0, 'mail' => 'erika@example.com', 'order_items' => $items,
        'state' => 'draft', 'billing_profile' => $profile, 'payment_gateway' => $gw->id()]);
    $order->set('coupons', [$coupon]);
    $order->addAdjustment(new Adjustment(['type' => 'custom', 'label' => 'Versand', 'amount' => new Price('4.90', 'EUR'), 'locked' => TRUE]));
    $order->save();
    \Drupal::service('commerce_order.order_refresh')->refresh($order);
    $order->save();

    $payment = Payment::create(['state' => 'new', 'amount' => $order->getBalance(), 'payment_gateway' => $gw->id(), 'order_id' => $order->id()]);
    $form = \Drupal::service('plugin_form.factory')->createInstance($gw->getPlugin(), 'offsite-payment');
    $form->setEntity($payment);
    @unlink('/tmp/fake-invoices.jsonl');
    $redirect = '';
    try {
        $form->buildConfigurationForm(['#return_url' => 'https://shop.example/checkout/1/payment/return', '#cancel_url' => 'https://shop.example/cancel', '#capture' => TRUE], new FormState());
    } catch (\Drupal\commerce\Response\NeedsRedirectException $e) {
        // REDIRECT_GET: Commerce sends the customer to the checkout link.
        $redirect = $e->getResponse()->getTargetUrl();
    }
    $check("$provider: customer redirected to the checkout link", $redirect === 'https://pay.example/i/INV-1', $redirect);
    $lines = file('/tmp/fake-invoices.jsonl') ?: [];
    $b = json_decode((string) end($lines), true) ?: [];
    $m = $b['metadata'] ?? [];
    $id = (string) $order->id();
    $check("$provider: top-level orderId is the order ID", ($b['orderId'] ?? null) === $id, $b['orderId'] ?? null);
    $check("$provider: metadata.orderId / orderNumber", ($m['orderId'] ?? null) === $id && ($m['orderNumber'] ?? null) === $id, $m);
    $check("$provider: amount = order total after promotion", ($b['amount'] ?? null) === '108.90' || (float) ($b['amount'] ?? 0) == (float) $order->getTotalPrice()->getNumber(), [$b['amount'] ?? null, $order->getTotalPrice()->getNumber()]);
    $check("$provider: orderUrl", ($m['orderUrl'] ?? null) === 'https://shop.example/admin/commerce/orders/' . $id, $m['orderUrl'] ?? null);
    $check("$provider: buyer", ($b['buyerEmail'] ?? null) === 'erika@example.com' && ($m['customerName'] ?? null) === 'Erika Mustermann'
        && ($m['buyerName'] ?? null) === 'Erika Mustermann' && ($m['buyerEmail'] ?? null) === 'erika@example.com', $m);
    $check("$provider: address", ($m['buyerCompany'] ?? null) === 'Muster & Co' && ($m['buyerAddress1'] ?? null) === 'Hauptstr. 5' && ($m['buyerAddress2'] ?? null) === 'Hinterhaus'
        && ($m['buyerCity'] ?? null) === 'Berlin' && ($m['buyerZip'] ?? null) === '10115' && ($m['buyerCountry'] ?? null) === 'DE', $m);
    $check("$provider: line items", count($m['lineItems'] ?? []) === 2 && ($m['lineItems'][0]['name'] ?? null) === 'Tasse' && ($m['lineItems'][0]['quantity'] ?? null) == 2
        && ($m['lineItems'][0]['price'] ?? null) == 19.5 && strpos((string) ($m['lineItems'][0]['sku'] ?? ''), 'MUG-1') === 0 && isset($m['lineItems'][0]['productId']), $m['lineItems'] ?? null);
    $check("$provider: itemDesc / itemCode", ($m['itemDesc'] ?? null) === '2 x Tasse, T-Shirt' && strpos((string) ($m['itemCode'] ?? ''), 'MUG-1') === 0, [$m['itemDesc'] ?? null, $m['itemCode'] ?? null]);
    $check("$provider: totals / coupon", ($m['subtotal'] ?? null) == 109 && ($m['discountTotal'] ?? null) == 5 && ($m['orderTotal'] ?? null) == (float) $order->getTotalPrice()->getNumber()
        && ($m['couponCodes'] ?? null) === [$coupon->getCode()] && ($m['currency'] ?? null) === 'EUR', $m);
    $check("$provider: platform / store / language", ($m['platform'] ?? null) === 'drupal_commerce' && ($m['storeName'] ?? null) === 'Meta Test Store'
        && ($m['language'] ?? null) === 'en' && ($m['pluginVersion'] ?? null) === COINSNAP_DRUPAL_VERSION, $m);
    $check("$provider: no empty values", $m !== [] && !in_array('', $m, true) && !in_array(null, $m, true));
    $check("$provider: invoice recorded on the order (webhook data unchanged)", isset(Order::load($order->id())->getData('coinsnap_invoices')['INV-1']));
    if ($provider === 'coinsnap') { echo json_encode($b, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"; }
}
echo "\n$passed passed, $failed failed\n";
