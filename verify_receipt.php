<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/paystack_helper.php';

$no  = strtoupper(trim($_GET['r'] ?? ''));
$sig = strtoupper(trim($_GET['s'] ?? ''));
$r = null;
if (preg_match('/^RCT-\d{8}-\d{5}$/', $no) && hash_equals(ps_sig($no), $sig)) {
    $r = ps_receipt_row(db(), 'receipt_no', $no);
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verify receipt | NYMIX TECH</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0f1117;color:#e8eaf0;font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background-image:radial-gradient(ellipse at 50% 0%,rgba(245,158,11,.14),transparent 60%)}
.c{width:100%;max-width:400px;background:#181c27;border:1px solid #2a2f42;border-radius:24px;padding:34px 26px;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.5)}
.ic{width:84px;height:84px;border-radius:50%;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;font-size:2.4rem}
.ok .ic{background:rgba(34,197,94,.14);border:2px solid #22c55e}.bad .ic{background:rgba(239,68,68,.14);border:2px solid #ef4444}
h1{font-family:'Space Grotesk',sans-serif;font-size:1.3rem}.ok h1{color:#22c55e}.bad h1{color:#ef4444}
.amt{font-family:'Space Grotesk',sans-serif;font-size:2rem;font-weight:700;color:#f59e0b;margin:14px 0 4px}
.r{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px dashed #2a2f42;font-size:.84rem;text-align:left}
.r span{color:#6b7280}.r b{text-align:right}
p{color:#6b7280;font-size:.85rem;line-height:1.6;margin-top:8px}
.f{margin-top:20px;font-size:.68rem;color:#6b7280}
</style></head><body>
<?php if ($r): ?>
<div class="c ok"><div class="ic">✅</div><h1>Genuine NYMIX receipt</h1>
  <div class="amt">KES <?= number_format((float)$r['amount'], 2) ?></div>
  <div style="margin-top:14px">
    <div class="r"><span>Receipt</span><b><?= $h($r['receipt_no']) ?></b></div>
    <div class="r"><span>Paid by</span><b><?= $h($r['business_name']) ?></b></div>
    <div class="r"><span>Invoice</span><b><?= $h($r['invoice_no'] ?: '—') ?></b></div>
    <div class="r"><span>Method</span><b><?= $h(ps_channel_label($r['channel'])) ?></b></div>
    <div class="r"><span>Date</span><b><?= $h(date('d M Y, H:i', strtotime($r['paid_at'] ?: $r['confirmed_at']))) ?></b></div>
  </div><div class="f">Verified by NYMIX TECH · Payments by Paystack</div></div>
<?php else: ?>
<div class="c bad"><div class="ic">⚠️</div><h1>Cannot verify</h1>
  <p>This receipt code is invalid or has been altered. Treat it as counterfeit and contact NYMIX TECH.</p></div>
<?php endif; ?>
</body></html>