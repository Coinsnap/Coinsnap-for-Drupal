<?php
// Submits the payment gateway settings form the way the admin page does, on a test site with this module and
// Commerce enabled:
//   drush php:script modules/custom/drupalcommerce_coinsnap/tests/drupal/gateway-form-test.php
// Point COINSNAP_SERVER_URL at a fake API in settings.php; without one only the "could not be reached" error is
// expected. Never run it against a live store: it registers a webhook.
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\Core\Form\FormState;

$failed = 0;
$passed = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$failed, &$passed) {
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : ": $detail") . "\n";
};

$warnings = [];
set_error_handler(function ($no, $msg, $file, $line) use (&$warnings) {
    $warnings[] = "$msg ($file:$line)";
    return true;
}, E_WARNING | E_NOTICE | E_USER_WARNING);

$submit = function (string $id, array $config) {
    $gateway = PaymentGateway::load($id) ?? PaymentGateway::create(['id' => $id, 'label' => $id, 'plugin' => 'coinsnap']);
    $formObject = \Drupal::entityTypeManager()->getFormObject('commerce_payment_gateway', $gateway->isNew() ? 'add' : 'edit');
    $formObject->setEntity($gateway);
    $formState = (new FormState())->setValues([
        'id' => $id,
        'label' => $id,
        'plugin' => 'coinsnap',
        'status' => 1,
        'configuration' => ['coinsnap' => $config + ['display_label' => 'Bitcoin', 'discount_type' => 'fixed']],
        'op' => 'Save',
    ]);
    \Drupal::formBuilder()->submitForm($formObject, $formState);
    return array_map('strval', $formState->getErrors());
};

$id = 'coinsnap_form_test_' . substr(md5((string) microtime(true)), 0, 6);

// Coinsnap: the hidden BTCPay fields stay empty, as the browser sends them.
$errors = $submit($id, ['provider' => 'coinsnap', 'store_id' => 'STORE-FORM-TEST', 'api_key' => 'key-form-test-1234',
    'webhook_base_url' => 'http://shop.example']);
$check('Coinsnap settings save without BTCPay fields', !preg_grep('/BTCPay .* field is required/', $errors), implode(' | ', $errors));
$check('Coinsnap Store ID still required', in_array('Coinsnap Store ID field is required.',
    $submit($id . 'b', ['provider' => 'coinsnap', 'store_id' => '', 'api_key' => 'k', 'webhook_base_url' => 'http://shop.example']), true));

// BTCPay: the hidden Coinsnap Store ID stays empty.
$errors = $submit($id . 'c', ['provider' => 'btcpay', 'btcpay_server_url' => 'http://btcpay.invalid', 'btcpay_store_id' => 'S',
    'btcpay_api_key' => 'k', 'webhook_base_url' => 'http://shop.example']);
$check('BTCPay settings save without Coinsnap Store ID', !in_array('Coinsnap Store ID field is required.', $errors, true), implode(' | ', $errors));
$check('BTCPay server URL still required', in_array('BTCPay server URL field is required.',
    $submit($id . 'd', ['provider' => 'btcpay', 'btcpay_server_url' => '', 'btcpay_store_id' => 'S', 'btcpay_api_key' => 'k',
        'webhook_base_url' => 'http://shop.example']), true));

// A save that passes validation on every version, so the submit handler runs.
$submit($id . 'e', ['provider' => 'coinsnap', 'store_id' => 'STORE-FORM-TEST', 'api_key' => 'key-form-test-1234',
    'btcpay_server_url' => 'http://btcpay.invalid', 'btcpay_store_id' => 'S', 'webhook_base_url' => 'http://shop.example']);
$modeWarnings = preg_grep('/Undefined array key "mode"/', $warnings);
$check('no "Undefined array key mode" warning on save', !$modeWarnings, implode(' | ', $modeWarnings));

foreach ([$id, $id . 'b', $id . 'c', $id . 'd', $id . 'e'] as $gid) {
    if ($g = PaymentGateway::load($gid)) {
        $g->delete();
    }
}
restore_error_handler();
echo "passed=$passed failed=$failed\n";
exit($failed > 0 ? 1 : 0);
