<?php
// admin/ai_autoreply.php — run by cron every minute (CLI only)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db.php';
if (!defined('GROQ_API_KEY')) { require_once __DIR__ . '/../includes/mail_config.php'; } // adjust path
require_once __DIR__ . '/../includes/admin_soc.php';
require_once __DIR__ . '/../includes/nx_support_ai.php';
$sent = 0;
for ($i = 0; $i < 5; $i++) { $sent += nxs_ai_run($conn, true); if ($i < 4) sleep(10); }
echo "NYMIX AI auto-reply: $sent message(s) sent\n";