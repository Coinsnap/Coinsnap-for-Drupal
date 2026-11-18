<?php
// Plain PHP checks of the webhook rules, without Drupal. Run with the oldest PHP the module supports and the newest:
//   docker run --rm -v "$PWD":/m -w /m php:7.4-cli php tests/webhook-rules-test.php
//   docker run --rm -v "$PWD":/m -w /m php:8.3-cli php tests/webhook-rules-test.php
require __DIR__ . '/../src/Webhook/Signature.php';
require __DIR__ . '/../src/Webhook/InvoiceDecision.php';
require __DIR__ . '/../src/Webhook/BtcpayKeyCheck.php';

use Drupal\drupalcommerce_coinsnap\Webhook\BtcpayKeyCheck;
use Drupal\drupalcommerce_coinsnap\Webhook\InvoiceDecision as D;
use Drupal\drupalcommerce_coinsnap\Webhook\Signature;

$failed = 0;
$passed = 0;
function check(string $name, $actual, $expected): void {
    global $failed, $passed;
    if ($actual === $expected) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL $name: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
}

$settled = ['status' => 'Settled', 'additionalStatus' => 'None', 'invoiceAmount' => '25.00', 'currency' => 'USD'];
check('settled, right amount', D::decide($settled, false, '25.00', 'USD'), D::PAID);
check('settled, 1 cent off', D::decide(['invoiceAmount' => '24.99'] + $settled, false, '25.00', 'USD'), D::PAID);
check('underpaid', D::decide(['invoiceAmount' => '20.00'] + $settled, false, '25.00', 'USD'), D::MISMATCH);
check('other currency', D::decide(['currency' => 'EUR'] + $settled, false, '25.00', 'USD'), D::MISMATCH);
check('zero price', D::decide(['invoiceAmount' => '0'] + $settled, false, '0', 'USD'), D::MISMATCH);
check('missing amount', D::decide(['status' => 'Settled', 'currency' => 'USD'], false, '25.00', 'USD'), D::MISMATCH);
check('fiatAmount fallback', D::decide(['status' => 'Settled', 'fiatAmount' => 25, 'currency' => 'USD'], false, '25.00', 'USD'), D::PAID);
check('overpaid still paid', D::decide(['additionalStatus' => 'Overpaid'] + $settled, false, '25.00', 'USD'), D::PAID);
check('btcpay Marked', D::decide(['status' => 'Settled', 'additionalStatus' => 'Marked', 'amount' => '25.00', 'currency' => 'USD'], true, '25.00', 'USD'), D::MISMATCH);
check('btcpay settled', D::decide(['status' => 'Settled', 'amount' => '25.00', 'currency' => 'USD'], true, '25.00', 'USD'), D::PAID);
check('Processing', D::decide(['status' => 'Processing'], false, '25.00', 'USD'), D::PROCESSING);
check('Expired', D::decide(['status' => 'Expired'], false, '25.00', 'USD'), D::EXPIRED);
check('New', D::decide(['status' => 'New'], false, '25.00', 'USD'), D::NONE);
check('Invalid', D::decide(['status' => 'Invalid'], false, '25.00', 'USD'), D::NONE);
check('minor units', D::toMinorUnits('1.234567895'), 123456790);

$body = '{"invoiceId":"abc"}';
$now = 1700000000;
$sig = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, 'sec');
check('good signature', Signature::coinsnapProblem($sig, $body, 'sec', $now), null);
check('wrong secret', Signature::coinsnapProblem($sig, $body, 'other', $now), Signature::BAD_SIGNATURE);
check('empty secret', Signature::coinsnapProblem('t=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, ''), $body, '', $now), Signature::BAD_SIGNATURE);
check('stale', Signature::coinsnapProblem($sig, $body, 'sec', $now + 301), Signature::STALE_TIMESTAMP);
check('no header', Signature::coinsnapProblem('', $body, 'sec', $now), Signature::BAD_SIGNATURE);
check('btcpay good', Signature::btcpayProblem('sha256=' . hash_hmac('sha256', $body, 'sec'), $body, 'sec'), null);
check('btcpay no prefix', Signature::btcpayProblem(hash_hmac('sha256', $body, 'sec'), $body, 'sec'), Signature::BAD_SIGNATURE);

check('btcpay key for this store', BtcpayKeyCheck::permissionProblem(array_map(fn($p) => "$p:S1", BtcpayKeyCheck::REQUIRED_PERMISSIONS), 'S1'), null);
check('btcpay key unrestricted', BtcpayKeyCheck::permissionProblem(BtcpayKeyCheck::REQUIRED_PERMISSIONS, 'S1') !== null, true);

echo "passed=$passed failed=$failed (PHP " . PHP_VERSION . ")\n";
exit($failed > 0 ? 1 : 0);
