<?php
// Plain-PHP checks for InfyniPayHandler::evaluateCapturedPayment(). No WHMCS needed:
//   php tests/captured-payment-check.php
// Exits 1 on the first failed expectation.

define('WHMCS', true);
require __DIR__ . '/../modules/addons/infynipay/infynipayhandler.php';

$failures = 0;

function expectCase(string $name, array $invoice, array $payment, array $expected): void
{
    global $failures;
    $actual = InfyniPayHandler::evaluateCapturedPayment($invoice, $payment);
    foreach ($expected as $key => $value) {
        if ($actual[$key] !== $value) {
            $failures++;
            echo "FAIL  $name: $key expected " . var_export($value, true) . ', got ' . var_export($actual[$key], true) . "\n";
            return;
        }
    }
    echo "ok    $name\n";
}

$unpaid = ['status' => 'Unpaid', 'balance' => '100.00', 'currencycode' => 'CAD'];

expectCase('exact match is recorded', $unpaid, ['amount' => '100.00', 'currency' => 'CAD', 'capturedAmount' => 100.0],
    ['record' => true, 'reason' => 'ok', 'amountMismatch' => false, 'capturedMismatch' => false]);

expectCase('currency compare ignores case and spaces', $unpaid, ['amount' => 100, 'currency' => ' cad '],
    ['record' => true]);

expectCase('already paid is not recorded again', ['status' => 'Paid', 'balance' => '0.00', 'currencycode' => 'CAD'],
    ['amount' => 100, 'currency' => 'CAD'], ['record' => false, 'reason' => 'already_paid']);

expectCase('cancelled invoice is not recorded', ['status' => 'Cancelled', 'balance' => '100.00', 'currencycode' => 'CAD'],
    ['amount' => 100, 'currency' => 'CAD'], ['record' => false, 'reason' => 'invoice_cancelled']);

expectCase('different currency is refused', $unpaid, ['amount' => 100, 'currency' => 'USD'],
    ['record' => false, 'reason' => 'currency_mismatch']);

expectCase('missing payment currency is not a reason to refuse', $unpaid, ['amount' => 100],
    ['record' => true]);

expectCase('unexpected currency shape is not a reason to refuse', $unpaid, ['amount' => 100, 'currency' => 1],
    ['record' => true]);

expectCase('zero amount is refused', $unpaid, ['amount' => 0, 'currency' => 'CAD'],
    ['record' => false, 'reason' => 'invalid_amount']);

expectCase('missing amount is refused', $unpaid, ['currency' => 'CAD'],
    ['record' => false, 'reason' => 'invalid_amount']);

expectCase('partial payment is recorded and flagged', $unpaid, ['amount' => 60, 'currency' => 'CAD'],
    ['record' => true, 'amountMismatch' => true]);

expectCase('overpayment is recorded and flagged', $unpaid, ['amount' => 150, 'currency' => 'CAD'],
    ['record' => true, 'amountMismatch' => true]);

expectCase('one-cent rounding is not a mismatch', $unpaid, ['amount' => 100.01, 'currency' => 'CAD'],
    ['record' => true, 'amountMismatch' => false]);

expectCase('captured amount that differs from the amount is flagged', $unpaid,
    ['amount' => 100, 'currency' => 'CAD', 'capturedAmount' => 80],
    ['record' => true, 'capturedMismatch' => true]);

expectCase('invoice without a balance field is not flagged', ['status' => 'Unpaid', 'currencycode' => 'CAD'],
    ['amount' => 100, 'currency' => 'CAD'], ['record' => true, 'amountMismatch' => false]);

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
