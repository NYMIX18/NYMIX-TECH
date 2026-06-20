<?php
define('PESAPAL_CONSUMER_KEY',    'aSEvuNgC8zNEUIiKWe9+O9mA38aB+WHp');
define('PESAPAL_CONSUMER_SECRET', 'gUWXIls6lQzFr/0WTjAURT/Q2Qc=');
define('PESAPAL_ENV',             'live');

define('PESAPAL_BASE_URL', PESAPAL_ENV === 'sandbox'
    ? 'https://cybqa.pesapal.com/pesapalv3'
    : 'https://pay.pesapal.com/v3');

define('PESAPAL_CALLBACK_URL', 'https://escalate-bazooka-pranker.ngrok-free.dev/nymix_hardwares/portal.php?page=payments&from_pesapal=1');
define('PESAPAL_IPN_URL',      'https://escalate-bazooka-pranker.ngrok-free.dev/nymix_hardwares/pesapal_ipn.php');