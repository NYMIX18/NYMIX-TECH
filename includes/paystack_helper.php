<?php
require_once __DIR__ . '/paystack_config.php';
require_once __DIR__ . '/mail_config.php';
date_default_timezone_set('Africa/Nairobi');

// ── Low-level Paystack call ─────────────────────────────────
function ps_api(string $method, string $path, ?array $body = null): array {
    $ch = curl_init(PAYSTACK_BASE . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . PAYSTACK_SECRET_KEY,
            'Content-Type: application/json',
            'Cache-Control: no-cache',
        ],
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $err) {
        error_log("[NYMIX PAYSTACK] curl: $err");
        return ['ok' => false, 'code' => $code, 'json' => [], 'msg' => 'Could not reach Paystack. Please try again.'];
    }
    $json = json_decode((string)$resp, true) ?: [];
    $ok   = $code >= 200 && $code < 300 && !empty($json['status']);
    if (!$ok) error_log("[NYMIX PAYSTACK] HTTP $code " . substr((string)$resp, 0, 300));
    return ['ok' => $ok, 'code' => $code, 'json' => $json, 'msg' => $json['message'] ?? 'Paystack error'];
}

// ── Receipt signing / URLs ──────────────────────────────────
function ps_sig(string $receipt_no): string {
    return strtoupper(substr(hash_hmac('sha256', $receipt_no, PAYSTACK_RECEIPT_SECRET), 0, 12));
}
function ps_verify_url(string $receipt_no): string {
    return NX_SITE_URL . '/verify_receipt.php?r=' . urlencode($receipt_no) . '&s=' . ps_sig($receipt_no);
}
function ps_channel_label(?string $c): string {
    return ['mobile_money' => 'M-Pesa', 'card' => 'Card', 'bank' => 'Bank', 'bank_transfer' => 'Bank transfer',
            'ussd' => 'USSD', 'qr' => 'QR'][$c ?? ''] ?? ($c ? ucfirst($c) : 'Paystack');
}

// ── Start a checkout for one invoice ────────────────────────
function ps_start_checkout(PDO $db, int $client_id, array $inv, string $email): array {
    $amount = (float)$inv['total'];
    if ($amount <= 0) return ['ok' => false, 'msg' => 'Invalid invoice amount.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'msg' => 'Your account has no valid email. Contact NYMIX TECH.'];

    $ref = 'NX-' . $client_id . '-' . (int)$inv['id'] . '-' . strtoupper(bin2hex(random_bytes(4)));

    try {
        $db->prepare("INSERT INTO subscription_payments
            (client_id, invoice_id, amount, payment_method, provider, reference, payment_date, note, confirmed)
            VALUES (?,?,?,'paystack','paystack',?,CURDATE(),'Paystack payment initiated',0)")
           ->execute([$client_id, (int)$inv['id'], $amount, $ref]);
    } catch (Throwable $e) {
        error_log('[NYMIX PAYSTACK] insert: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'Could not start payment. Please try again.'];
    }

    $r = ps_api('POST', '/transaction/initialize', [
        'email'        => $email,
        'amount'       => (int)round($amount * 100),      // KES -> cents
        'currency'     => 'KES',
        'reference'    => $ref,
        'callback_url' => PAYSTACK_CALLBACK_URL,
        'metadata'     => [
            'client_id' => $client_id, 'invoice_id' => (int)$inv['id'], 'invoice_no' => $inv['invoice_no'] ?? '',
            'custom_fields' => [['display_name' => 'Invoice', 'variable_name' => 'invoice_no', 'value' => $inv['invoice_no'] ?? '']],
        ],
    ]);

    if (!$r['ok'] || empty($r['json']['data']['authorization_url'])) {
        $db->prepare("DELETE FROM subscription_payments WHERE reference=? AND confirmed=0")->execute([$ref]);
        return ['ok' => false, 'msg' => 'Payment gateway error: ' . $r['msg']];
    }
    return ['ok' => true, 'url' => $r['json']['data']['authorization_url'], 'reference' => $ref];
}

// ── Verify with Paystack, then mark paid (callback + webhook both use this) ──
function ps_confirm(PDO $db, string $ref): array {
    $v = ps_api('GET', '/transaction/verify/' . rawurlencode($ref));
    if (!$v['ok']) return ['ok' => false, 'msg' => $v['msg']];
    $tx = $v['json']['data'] ?? [];
    if (($tx['status'] ?? '') !== 'success') {
        return ['ok' => false, 'msg' => 'Payment was ' . ($tx['status'] ?? 'not completed') . '.'];
    }
    return ps_mark_paid($db, $ref, $tx);
}

// Idempotent: safe if callback and webhook arrive at the same time
function ps_mark_paid(PDO $db, string $ref, array $tx): array {
    $db->beginTransaction();
    try {
        $st = $db->prepare("SELECT * FROM subscription_payments WHERE reference=? LIMIT 1 FOR UPDATE");
        $st->execute([$ref]);
        $pay = $st->fetch();
        if (!$pay) { $db->rollBack(); return ['ok' => false, 'msg' => 'Unknown payment reference.']; }
        if ((int)$pay['confirmed'] === 1) { $db->commit(); return ['ok' => true, 'already' => true, 'payment' => $pay]; }

        $paid = ((float)($tx['amount'] ?? 0)) / 100;
        if (($tx['currency'] ?? 'KES') !== 'KES' || $paid + 0.01 < (float)$pay['amount']) {
            $db->rollBack();
            error_log("[NYMIX PAYSTACK] amount mismatch ref=$ref paid=$paid expected={$pay['amount']}");
            return ['ok' => false, 'msg' => 'Amount mismatch. Contact NYMIX TECH with reference ' . $ref];
        }

        $receipt = 'RCT-' . date('Ymd') . '-' . str_pad((string)$pay['id'], 5, '0', STR_PAD_LEFT);
        try { $paid_at = (new DateTime($tx['paid_at'] ?? 'now'))->setTimezone(new DateTimeZone('Africa/Nairobi'))->format('Y-m-d H:i:s'); }
        catch (Throwable $e) { $paid_at = date('Y-m-d H:i:s'); }

        $db->prepare("UPDATE subscription_payments SET confirmed=1, confirmed_at=NOW(), payment_date=CURDATE(),
                receipt_no=?, channel=?, paystack_id=?, paid_at=?, note='Paid via Paystack', raw_response=? WHERE id=?")
           ->execute([$receipt, $tx['channel'] ?? null, $tx['id'] ?? null, $paid_at, json_encode($tx), $pay['id']]);

        $cid = (int)$pay['client_id']; $inv_id = (int)($pay['invoice_id'] ?? 0);
        $activated = false;

        if ($inv_id) {
            $db->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=?")->execute([$inv_id]);
            $q = $db->prepare("SELECT COUNT(*) FROM invoices
                WHERE subscription_id = (SELECT subscription_id FROM invoices WHERE id=? LIMIT 1) AND status NOT IN ('paid','void')");
            $q->execute([$inv_id]);
            if ((int)$q->fetchColumn() === 0) {
                $db->prepare("UPDATE clients SET status='active' WHERE id=?")->execute([$cid]);
                $s = $db->prepare("SELECT * FROM subscriptions WHERE client_id=? ORDER BY id DESC LIMIT 1");
                $s->execute([$cid]);
                if ($sub = $s->fetch()) {
                    if (in_array($sub['status'], ['expired', 'grace', 'suspended', 'unpaid'])) {
                        $ns = date('Y-m-d');
                        $ne = date('Y-m-d', strtotime($ns . ' ' . match ($sub['billing_cycle'] ?? 'monthly') {
                            'annual' => '+1 year', 'quarterly' => '+3 months', default => '+1 month' }));
                        $db->prepare("UPDATE subscriptions SET status='active', start_date=?, end_date=? WHERE id=?")->execute([$ns, $ne, $sub['id']]);
                    } else {
                        $db->prepare("UPDATE subscriptions SET status='active' WHERE id=?")->execute([$sub['id']]);
                    }
                }
                $activated = true;
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[NYMIX PAYSTACK] mark_paid: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'Could not record payment. Contact NYMIX TECH with reference ' . $ref];
    }

    $row = ps_receipt_row($db, 'reference', $ref);
    try { if ($row) ps_send_receipt_email($row); } catch (Throwable $e) { error_log('[NYMIX PAYSTACK] mail: ' . $e->getMessage()); }
    return ['ok' => true, 'activated' => $activated, 'payment' => $row];
}

// ── Receipt data ($by = 'reference' | 'receipt_no') ─────────
function ps_receipt_row(PDO $db, string $by, string $val): ?array {
    $col = $by === 'receipt_no' ? 'sp.receipt_no' : 'sp.reference';
    $st = $db->prepare("SELECT sp.*, i.invoice_no, i.period_start, i.period_end,
            i.amount AS inv_amount, i.tax AS inv_tax, i.total AS inv_total,
            c.business_name, c.owner_name, c.email AS client_email, c.phone AS client_phone, c.kra_pin
        FROM subscription_payments sp
        LEFT JOIN invoices i ON i.id = sp.invoice_id
        JOIN clients c ON c.id = sp.client_id
        WHERE $col = ? AND sp.confirmed = 1 LIMIT 1");
    $st->execute([$val]);
    $row = $st->fetch() ?: null;
    // Older payments may have been confirmed without a receipt number. Create one so receipts never crash.
    if ($row && empty($row['receipt_no'])) {
        $when  = (string)($row['paid_at'] ?: ($row['confirmed_at'] ?: ($row['payment_date'] ?: 'now')));
        $stamp = date('Ymd', strtotime($when) ?: time());
        $row['receipt_no'] = 'RCT-' . $stamp . '-' . str_pad((string)$row['id'], 5, '0', STR_PAD_LEFT);
        try {
            $db->prepare("UPDATE subscription_payments SET receipt_no=? WHERE id=? AND (receipt_no IS NULL OR receipt_no='')")
               ->execute([$row['receipt_no'], $row['id']]);
        } catch (Throwable $e) { error_log('[NYMIX PAYSTACK] receipt backfill: ' . $e->getMessage()); }
    }
    return $row;
}

// ── Email (Brevo) ───────────────────────────────────────────
function ps_mail(string $to, string $name, string $subject, string $html): bool {
    if (!$to) return false;
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => json_encode([
            'sender' => ['name' => MAIL_FROM_NAME, 'email' => MAIL_FROM_EMAIL],
            'to' => [['email' => $to, 'name' => $name ?: $to]],
            'subject' => $subject, 'htmlContent' => $html,
        ]),
        CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . BREVO_API_KEY],
    ]);
    $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code < 200 || $code >= 300) { error_log("[NYMIX PAYSTACK MAIL] $code $resp"); return false; }
    return true;
}
function ps_send_receipt_email(array $r): bool {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $url = NX_SITE_URL . '/receipt.php?ref=' . urlencode($r['reference']);
    $html = '<div style="background:#0f1117;padding:28px 12px;font-family:Arial,sans-serif">
      <div style="max-width:520px;margin:0 auto;background:#181c27;border:1px solid #2a2f42;border-radius:20px;overflow:hidden">
        <div style="background:linear-gradient(135deg,#f59e0b,#fb923c);padding:26px;text-align:center;color:#000">
          <div style="font-size:30px">✅</div><div style="font-size:20px;font-weight:800">Payment received</div>
          <div style="font-size:12px;letter-spacing:2px">NYMIX TECH</div></div>
        <div style="padding:26px;color:#e8eaf0">
          <p style="margin:0 0 14px">Hi ' . $h($r['owner_name'] ?: $r['business_name']) . ', thank you. Your payment was successful and your account is active.</p>
          <div style="font-size:34px;font-weight:800;color:#f59e0b;margin:10px 0">KES ' . number_format((float)$r['amount'], 2) . '</div>
          <table width="100%" style="font-size:13px;color:#cbd5e1;border-collapse:collapse">
            <tr><td style="padding:6px 0;color:#6b7280">Receipt</td><td align="right"><b>' . $h($r['receipt_no']) . '</b></td></tr>
            <tr><td style="padding:6px 0;color:#6b7280">Invoice</td><td align="right">' . $h($r['invoice_no']) . '</td></tr>
            <tr><td style="padding:6px 0;color:#6b7280">Method</td><td align="right">' . $h(ps_channel_label($r['channel'])) . '</td></tr>
            <tr><td style="padding:6px 0;color:#6b7280">Date</td><td align="right">' . $h($r['paid_at']) . '</td></tr></table>
          <div style="text-align:center;margin-top:22px"><a href="' . $h($url) . '" style="background:#f59e0b;color:#000;text-decoration:none;font-weight:700;padding:12px 26px;border-radius:12px;display:inline-block">View &amp; print receipt</a></div>
        </div></div></div>';
    return ps_mail($r['client_email'], $r['owner_name'] ?: $r['business_name'], 'Payment receipt ' . $r['receipt_no'], $html);
}