<?php
// admin/soc_watch.php — run by cron every minute
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/onboarding_ai.php';   // nxo_mail (Brevo)
require_once __DIR__ . '/../includes/admin_soc.php';       // soc_ai
require_once __DIR__ . '/../includes/admin_soc_alerts.php';
echo 'NYMIX AI watch: ' . nx_soc_watch($conn, true) . " alert(s) emailed\n";