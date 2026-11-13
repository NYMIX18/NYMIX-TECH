<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/paystack_helper.php';

$ref = trim($_GET['reference'] ?? $_GET['trxref'] ?? '');
if (!preg_match('/^[A-Za-z0-9._=-]{6,100}$/', $ref)) { header('Location: ' . NX_PORTAL_URL . '?page=payments'); exit; }

$r = ps_confirm(db(), $ref);
if ($r['ok']) {
    header('Location: receipt.php?ref=' . urlencode($ref) . '&new=1'); exit;
}
$_SESSION['flash'] = ['msg' => 'Payment not completed. ' . $r['msg'], 'type' => 'error'];
header('Location: ' . NX_PORTAL_URL . '?page=payments'); exit;