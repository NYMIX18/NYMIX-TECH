<?php
// IntaSend checkout + confirmation. Independent of paystack_helper.php.
require_once __DIR__ . '/intasend_config.php';

function nxis_enabled(): bool {
    return defined('INTASEND_SECRET_KEY') && defined('INTASEND_PUBLISHABLE_KEY')
        && INTASEND_SECRET_KEY !== '' && strpos(INTASEND_SECRET_KEY, 'PASTE_') !== 0
        && strpos(INTASEND_PUBLISHABLE_KEY, 'PASTE_') !== 0;
}
function nxis_base(): string { return INTASEND_ENV === 'live' ? 'https://payment.intasend.com' : 'https://sandbox.intasend.com'; }

function nxis_api(string $path, array $payload, bool $auth = false): array {
    $ch = curl_init(nxis_base() . $path);
    $hdr = ['Content-Type: application/json', 'Accept: application/json'];
    if ($auth) $hdr[] = 'Authorization: Bearer ' . INTASEND_SECRET_KEY;   // checkout uses public_key in the body, no header
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => $hdr,
    ]);
    $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($err || $code < 200 || $code >= 300) error_log("[NXIS] $path HTTP $code $err " . substr((string)$resp, 0, 400));
    return ['code' => $code, 'data' => json_decode((string)$resp, true) ?: [], 'raw' => (string)$resp, 'err' => $err];
}

function nxis_phone(string $p): string {
    $d = preg_replace('/\D/', '', $p);
    if (strpos($d, '254') === 0) return $d;
    if (strpos($d, '0') === 0) return '254' . substr($d, 1);
    if (strlen($d) === 9) return '254' . $d;
    return $d;
}

function nxis_cols(PDO $db): array {
    static $c = null;
    if ($c === null) { $c = []; foreach ($db->query("SHOW COLUMNS FROM subscription_payments")->fetchAll(PDO::FETCH_ASSOC) as $r) $c[$r['Field']] = $r; }
    return $c;
}
// Makes sure payment_method / provider can hold the value 'intasend' (in case they are ENUMs)
function nxis_heal(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    try {
        foreach (['payment_method', 'provider'] as $k) {
            $c = nxis_cols($db);
            if (isset($c[$k]) && stripos($c[$k]['Type'], 'enum') !== false && stripos($c[$k]['Type'], 'intasend') === false)
                $db->exec("ALTER TABLE subscription_payments MODIFY `$k` VARCHAR(30) NULL");
        }
    } catch (Throwable $e) { error_log('[NXIS heal] ' . $e->getMessage()); }
}

function nxis_start_checkout(PDO $db, int $cid, array $inv, string $email): array {
    if (!nxis_enabled()) return ['ok' => false, 'msg' => 'IntaSend is not configured.'];
    $total = round((float)$inv['total'], 2);
    if ($total <= 0) return ['ok' => false, 'msg' => 'Invalid invoice amount.'];
    $cs = $db->prepare("SELECT business_name, owner_name, phone FROM clients WHERE id=? LIMIT 1"); $cs->execute([$cid]); $cl = $cs->fetch() ?: [];
    $name  = trim((string)($cl['owner_name'] ?: $cl['business_name'] ?: 'Client'));
    $parts = preg_split('/\s+/', $name, 2);
    $ref   = 'NXIS-' . (int)$inv['id'] . '-' . strtoupper(bin2hex(random_bytes(4)));
    $host  = parse_url(NX_PORTAL_BASE, PHP_URL_SCHEME) . '://' . parse_url(NX_PORTAL_BASE, PHP_URL_HOST);

    $r = nxis_api('/api/v1/checkout/', [
        'public_key'   => INTASEND_PUBLISHABLE_KEY,
        'amount'       => $total,
        'currency'     => 'KES',
        'email'        => $email,
        'first_name'   => $parts[0] ?: 'Client',
        'last_name'    => $parts[1] ?? '',
        'phone_number' => nxis_phone((string)($cl['phone'] ?? '')),
        'api_ref'      => $ref,
        'comment'      => 'NYMIX invoice ' . ($inv['invoice_no'] ?? $inv['id']),
        'host'         => $host,
        'redirect_url' => rtrim(dirname(NX_PORTAL_BASE . 'x'), '/') . '/intasend_return.php',
    ]);
    $url = (string)($r['data']['url'] ?? '');
    if ($r['code'] < 200 || $r['code'] >= 300 || $url === '')
        return ['ok' => false, 'msg' => 'Could not start the IntaSend payment.' . (defined('DEBUG_MODE') && DEBUG_MODE ? ' [' . substr($r['raw'], 0, 200) . ']' : ' Try again or use Paystack.')];

    try {
        nxis_heal($db);
        $cols = nxis_cols($db);
        $vals = ['client_id' => $cid, 'invoice_id' => (int)$inv['id'], 'amount' => $total, 'payment_method' => 'intasend', 'provider' => 'intasend',
                 'reference' => $ref, 'payment_date' => date('Y-m-d'), 'confirmed' => 0, 'created_at' => date('Y-m-d H:i:s'),
                 'note' => 'is_checkout:' . (string)($r['data']['id'] ?? '')];
        if (isset($cols['received_by']) && ($cols['received_by']['Null'] ?? '') === 'NO') $vals['received_by'] = (int)$db->query("SELECT id FROM super_admins ORDER BY id LIMIT 1")->fetchColumn();
        $f = []; $v = [];
        foreach ($vals as $k => $x) if (isset($cols[$k])) { $f[] = "`$k`"; $v[] = $x; }
        $db->prepare("INSERT INTO subscription_payments (" . implode(',', $f) . ") VALUES (" . implode(',', array_fill(0, count($f), '?')) . ")")->execute($v);
    } catch (Throwable $e) {
        error_log('[NXIS insert] ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'Could not record the payment.' . (defined('DEBUG_MODE') && DEBUG_MODE ? ' [' . $e->getMessage() . ']' : '')];
    }
    return ['ok' => true, 'url' => $url];
}

// Asks IntaSend directly (never trust webhook data alone)
function nxis_verify(string $id): array {
    $r = nxis_api('/api/v1/payment/status/', ['invoice_id' => $id], true);
    $inv = $r['data']['invoice'] ?? null;
    if (!is_array($inv)) return ['ok' => false];
    return ['ok' => true, 'state' => strtoupper((string)($inv['state'] ?? '')), 'api_ref' => (string)($inv['api_ref'] ?? ''),
            'value' => (float)($inv['value'] ?? 0), 'provider' => (string)($inv['provider'] ?? ''), 'invoice_id' => (string)($inv['invoice_id'] ?? $id),
            'mpesa_ref' => (string)($inv['mpesa_reference'] ?? '')];
}

function nxis_confirm(PDO $db, string $ref, array $info): bool {
    $st = $db->prepare("SELECT * FROM subscription_payments WHERE reference=? AND provider='intasend' LIMIT 1"); $st->execute([$ref]);
    $p = $st->fetch();
    if (!$p) return false;
    if ($p['confirmed']) return true;
    if (abs((float)$p['amount'] - (float)($info['value'] ?? 0)) > 1) { error_log("[NXIS] amount mismatch for $ref"); return false; }
    $cols = nxis_cols($db);
    $chan = stripos($info['provider'] ?? '', 'MPESA') !== false || stripos($info['provider'] ?? '', 'M-PESA') !== false ? 'mobile_money'
          : (stripos($info['provider'] ?? '', 'CARD') !== false ? 'card' : strtolower((string)($info['provider'] ?? 'intasend')));
    $db->beginTransaction();
    try {
        $set = ['confirmed=1']; $args = [];
        $extra = ['confirmed_at' => date('Y-m-d H:i:s'), 'paid_at' => date('Y-m-d H:i:s'), 'channel' => $chan,
                  'receipt_no' => 'RCT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3))),
                  'note' => trim(($p['note'] ?? '') . ' | is_invoice:' . ($info['invoice_id'] ?? '') . ' ' . ($info['mpesa_ref'] ?? ''))];
        foreach ($extra as $k => $v) if (isset($cols[$k])) { $set[] = "`$k`=?"; $args[] = $v; }
        $args[] = (int)$p['id'];
        $u = $db->prepare("UPDATE subscription_payments SET " . implode(',', $set) . " WHERE id=? AND confirmed=0");
        $u->execute($args);
        if ($u->rowCount() === 0) { $db->commit(); return true; }   // someone else confirmed it first

        $iid = (int)$p['invoice_id'];
        if ($iid) {
            $db->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=? AND status NOT IN ('paid','void')")->execute([$iid]);
            $sq = $db->prepare("SELECT subscription_id FROM invoices WHERE id=?"); $sq->execute([$iid]);
            $sid = (int)$sq->fetchColumn();
            if ($sid) {
                $cq = $db->prepare("SELECT COUNT(*) FROM invoices WHERE subscription_id=? AND status NOT IN ('paid','void')"); $cq->execute([$sid]);
                if ((int)$cq->fetchColumn() === 0) {
                    $db->prepare("UPDATE clients SET status='active' WHERE id=?")->execute([(int)$p['client_id']]);
                    $sb = $db->prepare("SELECT * FROM subscriptions WHERE id=?"); $sb->execute([$sid]); $sub = $sb->fetch();
                    if ($sub) {
                        if (in_array($sub['status'], ['unpaid', 'expired', 'grace', 'suspended'], true)) {
                            $start = date('Y-m-d');
                            $end   = date('Y-m-d', strtotime($start . ' ' . (['annual' => '+1 year', 'quarterly' => '+3 months'][$sub['billing_cycle']] ?? '+1 month')));
                            $db->prepare("UPDATE subscriptions SET status='active', start_date=?, end_date=?, grace_end_date=? WHERE id=?")
                               ->execute([$start, $end, date('Y-m-d', strtotime($end . ' +7 days')), $sid]);
                        } else {
                            $db->prepare("UPDATE subscriptions SET status='active' WHERE id=?")->execute([$sid]);
                        }
                    }
                }
            }
        }
        $db->commit();
        return true;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); error_log('[NXIS confirm] ' . $e->getMessage()); return false; }
}

// Heals payments where the webhook never arrived
function nxis_reconcile_pending(PDO $db, int $cid): void {
    if (!nxis_enabled()) return;
    $st = $db->prepare("SELECT reference, note, amount FROM subscription_payments WHERE client_id=? AND confirmed=0 AND provider='intasend' AND created_at > DATE_SUB(NOW(), INTERVAL 3 DAY) ORDER BY id DESC LIMIT 3");
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $p) {
        if (!preg_match('/is_checkout:([A-Za-z0-9\-]+)/', (string)$p['note'], $m)) continue;
        $v = nxis_verify($m[1]);
        if ($v['ok'] && $v['state'] === 'COMPLETE' && $v['api_ref'] === $p['reference']) nxis_confirm($db, $p['reference'], $v);
    }
}