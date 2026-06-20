<?php
require_once __DIR__ . '/pesapal_config.php';

// ── Get Bearer Token ───────────────────────────────────────
function pesapal_get_token(): ?string {
    $url  = PESAPAL_BASE_URL . '/api/Auth/RequestToken';
    $body = json_encode([
        'consumer_key'    => PESAPAL_CONSUMER_KEY,
        'consumer_secret' => PESAPAL_CONSUMER_SECRET,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false, // set true in production
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res['token'] ?? null;
}

// ── Register IPN & get notification_id ────────────────────
function pesapal_register_ipn(string $token): ?string {
    // Check if we already have a saved notification_id
    $cache_file = __DIR__ . '/pesapal_ipn_id.txt';
    if (file_exists($cache_file)) {
        $cached = trim(file_get_contents($cache_file));
        if ($cached) return $cached;
    }

    $url  = PESAPAL_BASE_URL . '/api/URLSetup/RegisterIPN';
    $body = json_encode([
        'url'                   => PESAPAL_IPN_URL,
        'ipn_notification_type' => 'GET',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $ipn_id = $res['ipn_id'] ?? null;
    if ($ipn_id) {
        file_put_contents($cache_file, $ipn_id); // save so we don't re-register every time
    }
    return $ipn_id;
}

// ── Submit Order → get redirect URL ───────────────────────
function pesapal_submit_order(string $token, string $ipn_id, array $order): ?array {
    $url  = PESAPAL_BASE_URL . '/api/Transactions/SubmitOrderRequest';
    $body = json_encode([
        'id'               => $order['merchant_ref'],
        'currency'         => 'KES',
        'amount'           => $order['amount'],
        'description'      => $order['description'],
        'callback_url'     => PESAPAL_CALLBACK_URL,
        'notification_id'  => $ipn_id,
        'billing_address'  => [
            'email_address' => $order['email']      ?? '',
            'phone_number'  => $order['phone']      ?? '',
            'first_name'    => $order['first_name'] ?? '',
            'last_name'     => $order['last_name']  ?? '',
        ],
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res; // contains redirect_url and order_tracking_id
}

// ── Check Transaction Status ───────────────────────────────
function pesapal_get_status(string $token, string $tracking_id): ?array {
    $url = PESAPAL_BASE_URL . '/api/Transactions/GetTransactionStatus?orderTrackingId=' . urlencode($tracking_id);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res;
}