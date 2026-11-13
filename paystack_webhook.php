<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/paystack_helper.php';

$body = file_get_contents('php://input');
$sig  = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
if (!$sig || !hash_equals(hash_hmac('sha512', $body, PAYSTACK_SECRET_KEY), $sig)) { http_response_code(401); exit; }

http_response_code(200);
$evt = json_decode($body, true) ?: [];
if (($evt['event'] ?? '') === 'charge.success') {
    $ref = $evt['data']['reference'] ?? '';
    if ($ref) ps_confirm(db(), $ref);   // re-verifies with Paystack before trusting
}
echo 'ok';