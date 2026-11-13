<?php
// IntaSend settings. Get keys from IntaSend dashboard → Developers → API Keys.
// Sandbox and live use DIFFERENT accounts and DIFFERENT keys.
define('INTASEND_ENV',             'sandbox');                   // 'sandbox' while testing, 'live' in production
define('INTASEND_PUBLISHABLE_KEY', 'CHANGE_ME');
define('INTASEND_SECRET_KEY',      'CHANGE_ME');
define('INTASEND_WEBHOOK_CHALLENGE', 'CHANGE_ME'); // you invent this; same value goes in the IntaSend webhook form
// Folder of your portal, with trailing slash. If your portal file is not index.php, add its name, e.g. .../nymix_hardwares/portal.php
define('NX_PORTAL_BASE',           'https://your-domain.example/client_portal.php');