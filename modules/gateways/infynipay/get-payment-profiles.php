<?php
/**
 * AJAX endpoint to fetch payment profiles from InfyniPay API
 *
 * GET  -> returns { success: true, profiles: [...] }
 *      -> GET with ?proxy=1&u=... proxies icon image
 * POST -> supports:
 *   - { logError: true, ... } (client-side error logging)
 *   - { profileId: 123 } (store selection in session)
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// Handle icon proxy mode (GET with ?proxy=1&u=...)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['proxy'])) {
    // Ensure absolutely no output before headers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // icon-proxy-guard.php ships in this same directory and both proxy entry
    // points require it. A missing file here is almost always a partial upload,
    // and without this check it surfaces as a bare fatal with no explanation.
    $guardPath = __DIR__ . '/icon-proxy-guard.php';
    if (!is_file($guardPath)) {
        error_log('InfyniPay icon proxy: missing ' . $guardPath
            . ' - re-upload modules/gateways/infynipay/ in full.');
        http_response_code(500);
        header('Content-Type: text/plain');
        echo 'Icon proxy misconfigured';
        exit;
    }
    require_once $guardPath;

    // Validates scheme/host/port/credentials and rebuilds the URL from the
    // parts that were actually checked. See icon-proxy-guard.php.
    $requestedUrl = isset($_GET['u']) ? (string) $_GET['u'] : '';
    $target = infynipay_iconProxyValidateUrl($requestedUrl);
    if ($target === false) {
        // Logged server-side only. Echoing the rejected host back to the caller
        // would leak it, but with no record at all a blocked icon is impossible
        // to diagnose.
        error_log('InfyniPay icon proxy blocked url=' . $requestedUrl);
        http_response_code(403);
        header('Content-Type: text/plain');
        echo 'Host not allowed';
        exit;
    }

    // Follows redirects manually, re-running the allowlist on every hop.
    $result = infynipay_iconProxyFetch($target['url']);

    if (!$result['ok']) {
        error_log(sprintf(
            'InfyniPay icon proxy upstream failure: host=%s, HTTP=%d, error=%s',
            $target['host'],
            $result['status'],
            $result['error'] !== '' ? $result['error'] : 'none'
        ));

        http_response_code(502);
        header('Content-Type: text/plain');
        echo 'Upstream failed';
        exit;
    }

    $finalPath = parse_url($result['url'], PHP_URL_PATH);
    $contentType = infynipay_iconProxyResolveContentType(
        $result['type'],
        $finalPath === null ? '' : $finalPath
    );

    if ($contentType === false) {
        error_log(sprintf(
            'InfyniPay icon proxy rejected content type: host=%s, type=%s',
            $target['host'],
            $result['type']
        ));

        http_response_code(502);
        header('Content-Type: text/plain');
        echo 'Unsupported content type';
        exit;
    }

    // Clear any output buffers before sending image
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    infynipay_iconProxySendHeaders($contentType, strlen($result['body']));
    echo $result['body'];
    exit;
}

// Ensure we can still emit JSON even if something echoes before headers.
if (!ob_get_level()) {
    ob_start();
}

/**
 * Emit a JSON response and end.
 */
$infynipayRespond = function (int $statusCode, array $payload): void {
    // Many servers replace/strip 4xx/5xx bodies. Always return 200 and carry the real status in JSON.
    $payload['httpStatus'] = $statusCode;

    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(200);
    }
    // Clear any buffered HTML/output before returning JSON.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode($payload);
    exit;
};

set_exception_handler(function (\Throwable $e) use ($infynipayRespond): void {
    // Can't rely on WHMCS logger here; init.php might not have loaded.
    error_log('InfyniPay get-payment-profiles exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $infynipayRespond(500, [
        'success' => false,
        'error' => 'Internal error',
        'detail' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
});

set_error_handler(function (int $severity, string $message, string $file, int $line) use ($infynipayRespond): bool {
    // Convert warnings/notices into exceptions so we can surface them.
    if (!(error_reporting() & $severity)) {
        return false;
    }

    $infynipayRespond(500, [
        'success' => false,
        'error' => 'PHP error',
        'detail' => $message,
        'file' => basename($file),
        'line' => $line,
    ]);
    return true;
});

register_shutdown_function(function () use ($infynipayRespond): void {
    $err = error_get_last();
    if (!$err) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($err['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    error_log('InfyniPay get-payment-profiles fatal: ' . ($err['message'] ?? '') . ' in ' . ($err['file'] ?? '') . ':' . ($err['line'] ?? ''));

    $infynipayRespond(500, [
        'success' => false,
        'error' => 'Fatal error',
        'detail' => $err['message'] ?? 'Unknown fatal error',
        'file' => isset($err['file']) ? basename((string) $err['file']) : null,
        'line' => $err['line'] ?? null,
    ]);
});

try {
    require_once __DIR__ . '/../../../init.php';
    require_once __DIR__ . '/../../addons/infynipay/infynipayhandler.php';
} catch (\Throwable $e) {
    $infynipayRespond(500, [
        'success' => false,
        'error' => 'Bootstrap failed',
        'detail' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $inputRaw = file_get_contents('php://input');
        $input = json_decode($inputRaw, true);
        if (!is_array($input)) {
            $input = [];
        }

        if (!empty($input['logError'])) {
            InfyniPayHandler::safeLogModuleCall(
                'get_payment_profiles_client_error',
                [
                    'errorType' => $input['errorType'] ?? 'fetch_error',
                    'errorMessage' => $input['errorMessage'] ?? 'Unknown error',
                    'httpStatus' => $input['httpStatus'] ?? null,
                ],
                [
                    'responseText' => $input['responseText'] ?? null,
                    'stack' => $input['stack'] ?? null,
                    'url' => $_SERVER['HTTP_REFERER'] ?? null,
                    'rawInputLength' => is_string($inputRaw) ? strlen($inputRaw) : null,
                ]
            );

            $infynipayRespond(200, ['success' => true]);
        }

        if (!empty($input['logClient'])) {
            $infynipayRespond(200, ['success' => true]);
        }

        $profileId = $input['profileId'] ?? null;
        if ($profileId !== null && $profileId !== '') {
            $_SESSION['infynipay_profile_id'] = $profileId;
            $infynipayRespond(200, ['success' => true]);
        }

        $infynipayRespond(400, [
            'success' => false,
            'error' => 'Missing profileId'
        ]);
    } catch (\Throwable $e) {
        InfyniPayHandler::safeLogModuleCall('get_payment_profiles_post_error', [], [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], $e->getTraceAsString());

        $infynipayRespond(500, [
            'success' => false,
            'error' => 'POST failed',
            'detail' => $e->getMessage(),
        ]);
    }
}

// GET: fetch profiles
try {
    $credentials = InfyniPayHandler::getGatewayCredentials();
    $appId = $credentials['appId'] ?? null;
    $token = $credentials['token'] ?? null;
    
    if (empty($appId) || empty($token)) {
        $infynipayRespond(400, [
            'success' => false,
            'error' => 'InfyniPay not configured'
        ]);
    }
    
    $url = InfyniPayHandler::infynipay_getPaymentAppBaseUrl() . "/apps/{$appId}/payment-profiles/payment-checkout-methods";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token}",
        'Accept: application/json',
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        InfyniPayHandler::safeLogModuleCall('get_payment_profiles_curl_failed', [], [
            'error' => 'cURL request failed',
            'curlError' => $curlError,
        ]);
        $infynipayRespond(502, [
            'success' => false,
            'error' => 'Upstream call failed',
            'detail' => $curlError,
        ]);
    }

    if ($response !== false && InfyniPayHandler::isAppInactiveError($response)) {
        InfyniPayHandler::markAppInactive($appId, $response);
        InfyniPayHandler::safeLogModuleCall('get_payment_profiles_app_inactive', [
            'appId' => $appId,
            'url' => $url,
        ], [
            'httpCode' => $httpCode,
            'responseSnippet' => substr((string) $response, 0, 500),
        ]);

        $infynipayRespond(503, [
            'success' => false,
            'appInactive' => true,
            'error' => InfyniPayHandler::getCustomerAppInactiveMessage(),
        ]);
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        InfyniPayHandler::clearAppInactiveState();
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $snippet = is_string($response) ? substr($response, 0, 500) : null;
        InfyniPayHandler::safeLogModuleCall('get_payment_profiles_api_error', [], [
            'error' => 'API returned error',
            'httpCode' => $httpCode,
            'responseSnippet' => $snippet,
        ]);
        $infynipayRespond($httpCode > 0 ? $httpCode : 502, [
            'success' => false,
            'error' => 'InfyniPay API returned error',
            'httpCode' => $httpCode,
            'details' => $snippet,
        ]);
    }

    $checkoutMethods = json_decode($response, true);
    
    if (!is_array($checkoutMethods)) {
        InfyniPayHandler::safeLogModuleCall('get_payment_profiles_invalid_json', [], [
            'error' => 'Invalid JSON response',
            'responsePreview' => substr($response, 0, 200),
        ]);
        $infynipayRespond(502, [
            'success' => false,
            'error' => 'Invalid JSON from InfyniPay API'
        ]);
    }
    
    // Flatten the response based on checkoutMethod
    $activeProfiles = [];
    foreach ($checkoutMethods as $index => $checkoutMethodGroup) {
        $checkoutMethod = $checkoutMethodGroup['checkoutMethod'] ?? '';
        
        if ($checkoutMethod === 'CreditCard') {
            // Extract card icons from paymentCheckoutMethodItems
            $items = $checkoutMethodGroup['paymentCheckoutMethodItems'] ?? [];
            
            $firstItem = !empty($items) ? $items[0] : [];
            
            // API returns iconUri1 and iconUri2 directly on the item
            // (PaymentCheckoutMethodItem.iconUri1 / .iconUri2).
            $iconUri1 = $firstItem['iconUri1'] ?? null;
            $iconUri2 = $firstItem['iconUri2'] ?? null;

            // With no icon URI the card row renders without an <img> at all,
            // which is indistinguishable from a broken proxy at the browser.
            // Record which it is.
            if (empty($iconUri1) && empty($iconUri2)) {
                InfyniPayHandler::safeLogModuleCall('card_icon_missing_from_api', [
                    'checkoutMethod' => 'CreditCard',
                ], [
                    'itemCount' => count($items),
                    'itemKeys' => $firstItem ? array_keys($firstItem) : [],
                ]);
            }
            
            $isSupportSubscription = $firstItem['isSupportSubscription'] ?? false;
            $isSupportSinglePayment = $firstItem['isSupportSinglePayment'] ?? true;
            
            // Add credit card icon with light and dark mode support from API
            $activeProfiles[] = [
                'checkoutMethod' => 'CreditCard',
                'paymentProfileId' => 'creditcard',
                'paymentProfileName' => 'Card',
                'currency' => null,
                'isActive' => true,
                'paymentProvider' => [
                    'provider' => 'CreditCard',
                    'iconUri1' => $iconUri1,
                    'iconUri2' => $iconUri2,
                ],
                'isSupportSubscription' => $isSupportSubscription,
                'isSupportSinglePayment' => $isSupportSinglePayment,
            ];
            
        } elseif ($checkoutMethod === 'ProviderHostedPage') {
            // Loop through all paymentCheckoutMethodItems
            $items = $checkoutMethodGroup['paymentCheckoutMethodItems'] ?? [];
            
            foreach ($items as $itemIndex => $item) {
                $profile = $item['paymentProfile'] ?? null;
                if ($profile && isset($profile['isActive']) && $profile['isActive'] === true) {
                    // Add additional fields from the item
                    $profile['isSupportSubscription'] = $item['isSupportSubscription'] ?? false;
                    $profile['isSupportSinglePayment'] = $item['isSupportSinglePayment'] ?? true;
                    $profile['paymentMethodAddMode'] = $item['paymentMethodAddMode'] ?? null;
                    $profile['checkoutMethod'] = $checkoutMethod;
                    $activeProfiles[] = $profile;
                }
            }
        }
    }

    $infynipayRespond(200, [
        'success' => true,
        'profiles' => array_values($activeProfiles),
    ]);
} catch (\Throwable $e) {
    InfyniPayHandler::safeLogModuleCall('get_payment_profiles_ajax_error', [], [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], $e->getTraceAsString());

    $infynipayRespond(500, [
        'success' => false,
        'error' => 'Internal error',
        'detail' => $e->getMessage(),
    ]);
}

