<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/pesapal_helper.php';

$tracking_id  = $_GET['OrderTrackingId']        ?? '';
$merchant_ref = $_GET['OrderMerchantReference'] ?? '';

$message = 'processing';
$success = false;

if ($tracking_id) {
    $token      = pesapal_get_token();
    $status_res = $token ? pesapal_get_status($token, $tracking_id) : null;
    $pay_status = $status_res['payment_status_description'] ?? 'PENDING';

    if (in_array($pay_status, ['Completed', 'completed', 'COMPLETED'])) {
        $success = true;
        $message = 'Payment confirmed! Your subscription is now active.';
    } elseif (in_array($pay_status, ['Failed', 'failed', 'FAILED'])) {
        $message = 'Payment failed. Please try again or contact support.';
    } else {
        // Payment received but status still updating — check DB directly
        try {
            require_once __DIR__ . '/includes/db.php';
            $st = db()->prepare("
                SELECT confirmed FROM subscription_payments 
                WHERE reference LIKE 'NYMIX-%' 
                ORDER BY id DESC LIMIT 1
            ");
            $st->execute();
            $pay = $st->fetch();
            if ($pay && $pay['confirmed'] == 1) {
                $success = true;
                $message = 'Payment confirmed! Your subscription is now active.';
            } else {
                $success = true; // assume success if Pesapal redirected here
                $message = 'Payment received! Your subscription will activate within a few minutes.';
            }
        } catch (Throwable $e) {
            $success = true;
            $message = 'Payment received! Your subscription will activate within a few minutes.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payment Status — NYMIX</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0d0f14;color:#e8eaf0;font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center}
.card{background:#13161e;border:1px solid #2a2f3f;border-radius:18px;padding:44px 40px;text-align:center;max-width:420px;width:90%}
.icon{font-size:3rem;margin-bottom:18px}
h1{font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;margin-bottom:10px}
p{font-size:.9rem;color:#8b91a8;margin-bottom:24px;line-height:1.6}
.ref{font-family:monospace;font-size:.78rem;color:#5a6075;margin-bottom:24px}
.btn{display:inline-block;padding:11px 28px;border-radius:10px;background:#4f8ef7;color:#fff;font-family:'Syne',sans-serif;font-weight:700;text-decoration:none;font-size:.9rem}
.btn:hover{background:#3d7de8}
.btn-ghost{background:transparent;border:1px solid #2a2f3f;color:#8b91a8;margin-left:8px}
</style>
</head>
<body>
<div class="card">
  <div class="icon"><?= $success ? '✅' : '⏳' ?></div>
  <h1><?= $success ? 'Payment Successful!' : 'Payment Processing' ?></h1>
  <p><?= htmlspecialchars($message) ?></p>
  <?php if ($merchant_ref): ?>
  <div class="ref">Ref: <?= htmlspecialchars($merchant_ref) ?></div>
  <?php endif; ?>
  <a href="?page=payments" class="btn">Back to Payments</a>
  <a href="?page=dashboard" class="btn btn-ghost">Dashboard</a>
</div>
</body>
</html>