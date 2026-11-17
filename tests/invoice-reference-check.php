<?php
// Plain-PHP checks for InfyniPayHandler::normalizeInvoiceReference(). No WHMCS needed:
//   php tests/invoice-reference-check.php
// Exits 1 on the first failed expectation.

define('WHMCS', true);
require __DIR__ . '/../modules/addons/infynipay/infynipayhandler.php';

$failures = 0;

function expectReference(string $name, $input, ?string $expected): void
{
    global $failures;
    $actual = InfyniPayHandler::normalizeInvoiceReference($input);
    if ($actual !== $expected) {
        $failures++;
        echo "FAIL  $name: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
        return;
    }
    echo "ok    $name\n";
}

expectReference('a plain invoice number is accepted', '3897', '3897');
expectReference('an int from a JSON payload is accepted', 3897, '3897');
expectReference('a long invoice number is accepted', '123456789012345678', '123456789012345678');

// ?invoiceid[]=1 is what crashed the GET branch.
expectReference('an array (invoiceid[]=1) is refused', ['1'], null);
expectReference('a nested array is refused', [['1']], null);
expectReference('an empty array is refused', [], null);
expectReference('an object is refused', new stdClass(), null);
expectReference('null is refused', null, null);
expectReference('an empty string is refused', '', null);
expectReference('zero-length after trim is refused', ' ', null);
expectReference('a boolean is refused', true, null);
expectReference('a float is refused', 12.5, null);
expectReference('a negative int is refused', -5, null);
expectReference('a negative number string is refused', '-5', null);
expectReference('digits followed by text are refused', '12abc', null);
expectReference('text is refused', 'abc', null);
expectReference('a path-like value is refused', '1/../x', null);
expectReference('a value with a query is refused', '1?x=2', null);
expectReference('a value with a newline is refused', "12\n", null);
expectReference('more than 18 digits is refused', '1234567890123456789', null);

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
