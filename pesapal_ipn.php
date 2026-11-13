<?php
/**
 * NYMIX TECH — Pesapal IPN Handler
 * Place this file at: nymix_hardwares/pesapal_ipn.php
 * Set PESAPAL_IPN_URL in pesapal_config.php to:
 *   https://nymix-tech.top/nymix_hardwares/pesapal_ipn.php
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/pesapal_helper.php';

// Pesapal sends GET: ?OrderNotificationType=MERCHANT&OrderTrackingId=xxx&OrderMerchantReference=NYMIX-1-xxx
$tracking_id   = $_GET['OrderTrackingId']         ?? '';
$merchant_ref  = $_GET['OrderMerchantReference']  ?? '';
$notif_type    = $_GET['OrderNotificationType']    ?? '';

// Always respond 200 immediately — Pesapal will retry if we don't
http_response_code(200);
header('Content-Type: application/json');

if (!$tracking_id || !$merchant_ref) {
    echo json_encode(['status' => 'ignored', 'reason' => 'missing params']);
    exit;
}

// Log it
ipn_log("IPN received | tracking=$tracking_id | ref=$merchant_ref | type=$notif_type");

try {
    $db = db();

    // 1. Find the pending payment by merchant reference
    $st = $db->prepare("
        SELECT * FROM subscription_payments
        WHERE reference = ? AND client_id IS NOT NULL
        LIMIT 1
    ");
    $st->execute([$merchant_ref]);
    $payment = $st->fetch();

    if (!$payment) {
        // Maybe already confirmed via redirect — check by tracking id
        $st2 = $db->prepare("SELECT * FROM subscription_payments WHERE reference = ? LIMIT 1");
        $st2->execute([$tracking_id]);
        $payment = $st2->fetch();
    }

    if (!$payment) {
        ipn_log("WARN: No payment found for ref=$merchant_ref or tracking=$tracking_id");
        echo json_encode(['status' => 'not_found']);
        exit;
    }

    // 2. Already confirmed — skip
    if ($payment['confirmed'] == 1) {
        ipn_log("INFO: Payment already confirmed, skipping | id={$payment['id']}");
        echo json_encode(['status' => 'already_confirmed']);
        exit;
    }

    // 3. Verify with Pesapal API — never trust IPN alone
    $token = pesapal_get_token();
    if (!$token) {
        ipn_log("ERROR: Could not get Pesapal token");
        echo json_encode(['status' => 'token_error']);
        exit;
    }

    $status_res = pesapal_get_status($token, $tracking_id);
    ipn_log("Pesapal status response: " . json_encode($status_res));

    $payment_status = strtoupper($status_res['payment_status_description'] ?? '');
    $pesapal_status = (int)($status_res['status_code'] ?? 0);

    // status_code: 1 = COMPLETED, 2 = FAILED, 3 = REVERSED
    // payment_status_description: "Completed", "Failed", "Invalid"
    $is_paid = ($pesapal_status === 1 || $payment_status === 'COMPLETED');

    if (!$is_paid) {
        ipn_log("INFO: Payment not completed yet. status_code=$pesapal_status description=$payment_status");
        echo json_encode(['status' => 'not_paid', 'pesapal_status' => $payment_status]);
        exit;
    }

    // 4. Payment confirmed — update everything in a transaction
    $db->beginTransaction();

    $pay_id = (int)$payment['id'];
    $cid    = (int)$payment['client_id'];
    $inv_id = (int)($payment['invoice_id'] ?? 0);

    // Mark payment confirmed, store tracking id as reference
    $db->prepare("
        UPDATE subscription_payments
        SET confirmed = 1, confirmed_at = NOW(), reference = ?
        WHERE id = ?
    ")->execute([$tracking_id, $pay_id]);

    // Mark invoice paid
    if ($inv_id) {
        $db->prepare("
            UPDATE invoices SET status = 'paid', paid_at = NOW()
            WHERE id = ? AND status NOT IN ('paid','void')
        ")->execute([$inv_id]);
    }

    // Check if all invoices for the subscription are now paid
    $all_st = $db->prepare("
        SELECT COUNT(*) FROM invoices
        WHERE subscription_id = (
            SELECT subscription_id FROM invoices WHERE id = ? LIMIT 1
        )
        AND status NOT IN ('paid','void')
    ");
    $all_st->execute([$inv_id]);
    $unpaid_count = (int)$all_st->fetchColumn();

    if ($unpaid_count === 0) {
        // Activate client
        $db->prepare("UPDATE clients SET status = 'active' WHERE id = ?")
           ->execute([$cid]);

        // Get latest subscription
        $sub_st = $db->prepare("
            SELECT * FROM subscriptions WHERE client_id = ? ORDER BY id DESC LIMIT 1
        ");
        $sub_st->execute([$cid]);
        $sub_row = $sub_st->fetch();

        if ($sub_row) {
            if (in_array($sub_row['status'], ['expired', 'grace', 'suspended'])) {
                $ns  = date('Y-m-d');
                $int = match($sub_row['billing_cycle'] ?? 'monthly') {
                    'annual'    => '+1 year',
                    'quarterly' => '+3 months',
                    default     => '+1 month',
                };
                $ne = date('Y-m-d', strtotime($ns . ' ' . $int));
                $db->prepare("
                    UPDATE subscriptions
                    SET status = 'active', start_date = ?, end_date = ?
                    WHERE id = ?
                ")->execute([$ns, $ne, $sub_row['id']]);
            } else {
                $db->prepare("UPDATE subscriptions SET status = 'active' WHERE id = ?")
                   ->execute([$sub_row['id']]);
            }
        }

        ipn_log("SUCCESS: Client $cid activated | invoice $inv_id marked paid | subscription renewed");
    } else {
        ipn_log("INFO: Invoice $inv_id paid but $unpaid_count invoice(s) still unpaid for client $cid");
    }

    $db->commit();

    echo json_encode(['status' => 'success', 'message' => 'Payment confirmed']);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    ipn_log("EXCEPTION: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}

// ── Simple file logger ─────────────────────────────────────
function ipn_log(string $msg): void {
    $log_file = __DIR__ . '/logs/pesapal_ipn.log';
    $dir = dirname($log_file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
}
