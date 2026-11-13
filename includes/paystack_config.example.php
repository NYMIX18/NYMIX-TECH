<?php
// NEVER share or commit this file.
define('PAYSTACK_SECRET_KEY', 'CHANGE_ME');   // sk_live_... when going live
define('PAYSTACK_PUBLIC_KEY', 'CHANGE_ME');
define('PAYSTACK_BASE',       'https://api.paystack.co');

// Where your portal lives (no trailing slash on SITE_URL)
define('NX_SITE_URL',   'CHANGE_ME');
define('NX_PORTAL_URL', NX_SITE_URL . '/index.php');       // <- your portal filename
define('PAYSTACK_CALLBACK_URL', NX_SITE_URL . '/paystack_callback.php');

// Signs receipt QR codes so they can't be forged. Make it long and random.
define('PAYSTACK_RECEIPT_SECRET', 'CHANGE_ME');