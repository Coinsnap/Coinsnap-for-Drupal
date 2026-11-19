<?php
// Router for `php -S`: a fake Coinsnap / BTCPay API for tests/drupal/invoice-metadata-test.php; it records every
// create-invoice body in /tmp/fake-invoices.jsonl.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#/api/v1/stores/[^/]+/invoices$#', $path)) {
    $body = file_get_contents('php://input');
    file_put_contents('/tmp/fake-invoices.jsonl', $body . "\n", FILE_APPEND);
    $n = count(file('/tmp/fake-invoices.jsonl'));
    echo json_encode(['id' => 'INV-' . $n, 'checkoutLink' => 'https://pay.example/i/INV-' . $n]);
    return true;
}
if (preg_match('#/payment-methods$#', $path)) { echo '[{"enabled":true,"paymentMethodId":"BTC-LN"}]'; return true; }
if (preg_match('#/rates$#', $path)) { echo '[{"currencyPair":"BTC_EUR","rate":"90000"}]'; return true; }
http_response_code(404);
echo '{}';
return true;
