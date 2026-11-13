<?php
define('PESAPAL_CONSUMER_KEY',    'CHANGE_ME');
define('PESAPAL_CONSUMER_SECRET', 'CHANGE_ME');
define('PESAPAL_ENV', 'sandbox');
define('PESAPAL_BASE_URL',
    PESAPAL_ENV === 'live'
        ? 'https://pay.pesapal.com/v3'
        : 'https://cybqa.pesapal.com/pesapalv3'
);
define('PESAPAL_IPN_URL',      'CHANGE_ME');
define('PESAPAL_CALLBACK_URL', 'CHANGE_ME');
// ── DARAJA (M-PESA) — SYMPOSIUM DEMO ONLY ─────────────────────
// Get your own free Consumer Key/Secret at developer.safaricom.co.ke
// (create an app under "Lipa Na M-Pesa Sandbox"). Shortcode + Passkey
// below are Safaricom's PUBLIC sandbox values — same for every dev.
define('DARAJA_CONSUMER_KEY',    'CHANGE_ME');
define('DARAJA_CONSUMER_SECRET', 'CHANGE_ME');
define('DARAJA_SHORTCODE',       'CHANGE_ME');
define('DARAJA_PASSKEY',         'CHANGE_ME');
define('DARAJA_ENV',             'sandbox');
define('DARAJA_BASE_URL',
    DARAJA_ENV === 'live'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke'
);
define('DARAJA_CALLBACK_URL', 'CHANGE_ME');
// Sandbox STK push ONLY works with this Safaricom test number.
define('DARAJA_TEST_PHONE', '254708374149');