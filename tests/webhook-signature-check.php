<?php
// Plain-PHP checks for the webhook signature verifier in InfyniPayHandler. No WHMCS needed:
//   php tests/webhook-signature-check.php
// Exits 1 on the first failed expectation.

define('WHMCS', true);
require __DIR__ . '/../modules/addons/infynipay/infynipayhandler.php';

$failures = 0;

function expectResult(string $name, string $expected, string $actual): void
{
    global $failures;
    if ($actual !== $expected) {
        $failures++;
        echo "FAIL  $name: expected $expected, got $actual\n";
        return;
    }
    echo "ok    $name\n";
}

function expectTrue(string $name, bool $condition): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo "FAIL  $name\n";
        return;
    }
    echo "ok    $name\n";
}

// The test vector from the hand-off document.
$secret = 'whsec_TESTVECTORdoNotUseInProduction0123456789';
$t      = 1785000000;
$body   = '{"paymentId":1001,"referenceId":"42","paymentState":"Captured"}';
$v1     = '488824c948e1542f7da22e7e94f64d07f1a61ffa8b40cc2c4e4e88986d1ae4c9';
$header = "t=$t,v1=$v1";

function sign(string $body, string $secret, int $t): string
{
    return "t=$t,v1=" . hash_hmac('sha256', $t . '.' . $body, $secret);
}

function verify(string $body, ?string $header, array $secrets, int $now): string
{
    return InfyniPayHandler::verifyWebhookSignature($body, $header, $secrets, $now);
}

expectResult('the published vector is valid', 'valid', verify($body, $header, [$secret], $t));
expectTrue('the vector really is what we compute', sign($body, $secret, $t) === $header);

$changedBody = substr($body, 0, -1) . ' }';
expectResult('one changed body byte is a mismatch', 'mismatch', verify($changedBody, $header, [$secret], $t));
expectResult('a wrong secret is a mismatch', 'mismatch', verify($body, $header, ['whsec_somethingElse'], $t));

expectResult('300 s old is still inside the window', 'valid', verify($body, $header, [$secret], $t + 300));
expectResult('300 s ahead is still inside the window', 'valid', verify($body, $header, [$secret], $t - 300));
expectResult('301 s old is outside the window', 'outside_window', verify($body, $header, [$secret], $t + 301));
expectResult('301 s in the future is outside the window', 'outside_window', verify($body, $header, [$secret], $t - 301));

$age = null;
InfyniPayHandler::verifyWebhookSignature($body, $header, [$secret], $t + 400, $age);
expectTrue('age is reported for an outside_window refusal', $age === 400);
InfyniPayHandler::verifyWebhookSignature($body, $header, [$secret], $t - 400, $age);
expectTrue('age is negative when the signed time is ahead', $age === -400);

expectResult('null header is missing', 'missing', verify($body, null, [$secret], $t));
expectResult('blank header is missing', 'missing', verify($body, '  ', [$secret], $t));
expectResult('header without v1 is malformed', 'malformed', verify($body, "t=$t", [$secret], $t));
expectResult('header without t is malformed', 'malformed', verify($body, "v1=$v1", [$secret], $t));
expectResult('v1 that is not 64 hex chars is malformed', 'malformed', verify($body, "t=$t,v1=abcd", [$secret], $t));
expectResult('v1 with non-hex characters is malformed', 'malformed',
    verify($body, "t=$t,v1=" . str_repeat('z', 64), [$secret], $t));
expectResult('t that is not digits is malformed', 'malformed', verify($body, "t=17850x0000,v1=$v1", [$secret], $t));
expectResult('negative t is malformed', 'malformed', verify($body, "t=-5,v1=$v1", [$secret], $t));

expectResult('uppercase hex v1 is still valid', 'valid', verify($body, "t=$t,v1=" . strtoupper($v1), [$secret], $t));
expectResult('an extra v2 pair is ignored', 'valid', verify($body, "t=$t,v1=$v1,v2=" . str_repeat('0', 64), [$secret], $t));
expectResult('pair order does not matter', 'valid', verify($body, "v1=$v1,t=$t", [$secret], $t));
expectResult('spaces around pairs are tolerated', 'valid', verify($body, "t=$t, v1=$v1", [$secret], $t));

expectResult('two secrets, only the second matches', 'valid', verify($body, $header, ['whsec_old', $secret], $t));
expectResult('two secrets, only the first matches', 'valid', verify($body, $header, [$secret, 'whsec_old'], $t));
expectResult('two secrets, neither matches', 'mismatch', verify($body, $header, ['whsec_a', 'whsec_b'], $t));
expectResult('empty secret list is unverifiable', 'unverifiable', verify($body, $header, [], $t));
expectResult('only empty secrets is unverifiable', 'unverifiable', verify($body, $header, ['', ''], $t));
expectResult('a missing header is reported before a missing secret', 'missing', verify($body, null, [], $t));

// The secret is the literal whsec_ string: decoding it as base64 must not give the same key.
$decodedKey = base64_decode(strtr(substr($secret, 6), '-_', '+/'), false);
expectResult('a base64-decoded secret does not verify', 'mismatch',
    verify($body, $header, [$decodedKey === false ? 'x' : $decodedKey], $t));

// Header selection: any "-Signature" header carrying v1=, whatever the brand is called.
$select = 'InfyniPayHandler::selectWebhookSignatureHeader';
expectTrue('selects a header by suffix, from $_SERVER style names',
    $select(['CONTENT_TYPE' => 'application/json', 'X_SOMEBRAND_SIGNATURE' => $header]) === $header);
expectTrue('selects a header by suffix, from getallheaders() style names',
    $select(['Content-Type' => 'application/json', 'X-Other-Brand-Signature' => $header]) === $header);
expectTrue('name case is ignored', $select(['x-brand-signature' => $header]) === $header);
expectTrue('a -Signature header without v1= is not selected', $select(['X-Brand-Signature' => 'sha256=abc']) === null);
expectTrue('a v1= value under another name is not selected', $select(['X-Brand-Digest' => $header]) === null);
expectTrue('a blank value is not selected', $select(['X-Brand-Signature' => '']) === null);
expectTrue('no headers selects nothing', $select([]) === null);

$otherBrand = $select(['X-Another-Brand-Signature' => $header]);
expectResult('a header under a different brand is valid', 'valid', verify($body, $otherBrand, [$secret], $t));

// Request headers: $_SERVER style names are picked up.
$_SERVER['HTTP_X_TESTBRAND_SIGNATURE'] = $header;
expectTrue('collectRequestHeaders reads $_SERVER', InfyniPayHandler::collectRequestHeaders()['X_TESTBRAND_SIGNATURE'] === $header);
expectTrue('collected headers select the signature',
    InfyniPayHandler::selectWebhookSignatureHeader(InfyniPayHandler::collectRequestHeaders()) === $header);
unset($_SERVER['HTTP_X_TESTBRAND_SIGNATURE']);

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
