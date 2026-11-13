<?php
// ============================================================
//  identity_verify.php: identity check for staff registration
//  Nothing is stored: no images, no OCR text, no face data.
//  Needs PHP 8.1+ (same as staff_portal.php).
//
//  ID reading supports:
//   - Maisha Card (3rd gen, 9-digit number, MRZ on the back)
//   - 2nd generation ID (ID NUMBER + separate SERIAL NUMBER)
//   - older laminated / 1st generation IDs (6-8 digit numbers)
//  The ID number is located by its LABEL (ID NUMBER, ID NO,
//  MAISHA NAMBA, NAMBA YA KITAMBULISHO...) so the card serial
//  number is never mistaken for the ID number.
//
//  POST fields for ?action=id
//    ocr_text   : OCR text of the front of the ID   (required)
//    ocr_back   : OCR text of the back of the ID    (optional, recommended
//                 for Maisha Cards because of the MRZ)
//    ocr_number : NEW, optional. OCR of a CROPPED, digits-only pass over the
//                 area of the front where the ID NUMBER is printed. Much more
//                 reliable than the full-card OCR on busy card backgrounds.
//
//  CHANGES IN THIS VERSION
//   1. Dates (e.g. 15.05.2008) are stripped before digit runs are built, so a
//      date of birth can no longer be glued into a fake 8-digit "ID number".
//   2. New ocr_number field (digits-only crop) is checked against the record.
//   3. When no ID label could be read, a digit run with the same length as the
//      stored ID and at most 1 OCR slip is accepted (name must also match).
//   4. Debug output shows the new fields.
// ============================================================
ini_set('display_errors', 0);
error_reporting(0);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure', 1);
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const IDV_MAX_FAILS  = 3;     // total failed checks allowed per registration session
const IDV_FACE_BEST  = 0.60;  // best selfie-vs-ID distance must be <= this (face-api: lower = more similar)
const IDV_FACE_AVG   = 0.65;  // average of the 3 selfie frames must be <= this
const IDV_SAME_IMAGE = 0.12;  // closer than this = the same picture, not a live face
const IDV_ID_FRESH   = 900;   // seconds the ID step stays valid
const IDV_DEBUG      = false;  // TESTING ONLY: shows why an ID failed (masked). Set to false before going live.

function out(array $a): never { session_write_close(); echo json_encode($a); exit; }

function idv_fail(string $msg, string $where = ''): never {
    $_SESSION['idv_fail'] = (int)($_SESSION['idv_fail'] ?? 0) + 1;
    $left = max(0, IDV_MAX_FAILS - $_SESSION['idv_fail']);
    out(['ok' => false, 'where' => $where, 'locked' => $left === 0, 'review' => $left === 0,
         'msg' => $left ? $msg . " ({$left} attempt" . ($left === 1 ? '' : 's') . ' left)'
                        : 'Too many failed attempts. Please contact your branch manager.']);
}

function idv_vec($v): ?array {
    if (!is_array($v) || count($v) !== 128) return null;
    $o = []; $mag = 0.0;
    foreach ($v as $x) {
        if (!is_numeric($x)) return null;
        $f = (float)$x;
        if (!is_finite($f) || abs($f) > 3) return null;
        $o[] = $f; $mag += abs($f);
    }
    return $mag > 1 ? $o : null;
}
function idv_dist(array $a, array $b): float {
    $s = 0.0;
    for ($i = 0; $i < 128; $i++) { $d = $a[$i] - $b[$i]; $s += $d * $d; }
    return sqrt($s);
}

// ── OCR helpers ───────────────────────────────────────────────

/** Fix common OCR letter/digit confusions, but only inside tokens that are mostly digits. */
function idv_fix_token(string $t): string {
    $len = strlen($t);
    if ($len < 6) return $t;
    $digits = preg_match_all('/\d/', $t);
    if ($digits < $len * 0.6) return $t;
    return strtr($t, ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', '|' => '1',
                      'Z' => '2', 'S' => '5', 'B' => '8']);
}

/** Remove dates like 15.05.2008, 15/05/2008, 15-05-08 so they are never mistaken for ID digits. */
function idv_strip_dates(string $s): string {
    return preg_replace('/(?<!\d)\d{1,2}\s*[.\/\-]\s*\d{1,2}\s*[.\/\-]\s*\d{2,4}(?!\d)/', ' ', $s);
}

/** Digit runs (6-10 digits) found in a text fragment, tolerant of OCR spacing and confusions. */
function idv_digit_runs(string $seg): array {
    $seg = idv_strip_dates($seg);                       // FIX 1: dates can't become fake ID numbers
    $seg = preg_replace('/[^A-Z0-9|]+/', ' ', strtoupper($seg));
    $tokens = array_values(array_filter(explode(' ', trim($seg)), fn($t) => $t !== ''));
    $runs = [];
    $group = '';
    foreach ($tokens as $t) {
        $f = idv_fix_token($t);
        if (ctype_digit($f)) {
            if (strlen($f) >= 6 && strlen($f) <= 10) $runs[] = $f;
            // "332 184 998" written with spaces: join short groups
            if (strlen($f) >= 2) $group .= $f; else $group = '';
        } else {
            if (strlen($group) >= 6 && strlen($group) <= 10) $runs[] = $group;
            $group = '';
        }
    }
    if (strlen($group) >= 6 && strlen($group) <= 10) $runs[] = $group;
    return array_values(array_unique($runs));
}

/** True when $run equals $nid, or is within one OCR slip of it (same length +/- 1). */
function idv_close(string $run, string $nid): bool {
    if ($run === $nid) return true;
    return abs(strlen($run) - strlen($nid)) <= 1 && levenshtein($run, $nid) <= 1;
}

/**
 * Finds numbers that sit next to an "ID NUMBER"-type label and numbers that sit next to a
 * "SERIAL"-type label, on the same line or on the line right below it.
 * Returns ['id' => [...], 'serial' => [...]].
 */
function idv_label_scan(array $lines): array {
    $idRe  = '/\bID\.?\s*N[A-Z0-9.]{1,7}\b|MAISHA\s*NAMBA|NAMBA\s*YA\s*(?:KITAMBULISHO|UTAMBULISHO|MAISHA)|\bUPI\b/';
    $serRe = '/SERIAL(?:\s*N[A-Z0-9.]{1,7})?|\bSER\.?\s*N[A-Z0-9.]{1,4}\b|CARD\s*N[A-Z0-9.]{1,7}/';
    $res = ['id' => [], 'serial' => []];

    $labelsOf = function (string $line) use ($idRe, $serRe): array {
        $found = [];
        foreach (['id' => $idRe, 'serial' => $serRe] as $type => $re) {
            if (preg_match_all($re, $line, $mm, PREG_OFFSET_CAPTURE)) {
                foreach ($mm[0] as [$txt, $off]) $found[] = ['type' => $type, 'start' => $off, 'end' => $off + strlen($txt)];
            }
        }
        usort($found, fn($a, $b) => $a['start'] <=> $b['start']);
        return $found;
    };

    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $labels = $labelsOf($lines[$i]);
        if (!$labels) continue;
        foreach ($labels as $k => $lb) {
            $segEnd = $labels[$k + 1]['start'] ?? strlen($lines[$i]);
            $seg = substr($lines[$i], $lb['end'], max(0, $segEnd - $lb['end']));
            $runs = idv_digit_runs($seg);
            // value printed on the next line (common on 2nd-gen and Maisha layouts)
            if (!$runs && $k === count($labels) - 1 && isset($lines[$i + 1]) && !$labelsOf($lines[$i + 1])) {
                $runs = idv_digit_runs($lines[$i + 1]);
            }
            foreach ($runs as $r) $res[$lb['type']][] = $r;
        }
    }
    $res['id'] = array_values(array_unique($res['id']));
    $res['serial'] = array_values(array_unique($res['serial']));
    return $res;
}

/** MRZ lines (ICAO 9303, TD1 on the Maisha Card back): letters, digits and '<' only, long, with fillers. */
function idv_mrz_lines(array $lines): array {
    $o = [];
    foreach ($lines as $ln) {
        $c = preg_replace('/\s+/', '', $ln);
        $c = strtr($c, ['«' => '<', '‹' => '<', '{' => '<', '(' => '<', '[' => '<']);
        if (strlen($c) >= 24 && preg_match('/^[A-Z0-9<]+$/', $c) && substr_count($c, '<') >= 2) $o[] = $c;
    }
    return $o;
}

// ── Request guards ────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'msg' => 'Bad request.']);

$m = $_SESSION['reg_match'] ?? null;
if (!$m || empty($_SESSION['reg_card_ok']) || empty($_SESSION['reg_email_ok'])) {
    out(['ok' => false, 'restart' => true, 'msg' => 'Your session expired. Please start registration again.']);
}
if ((int)($_SESSION['idv_fail'] ?? 0) >= IDV_MAX_FAILS) {
    out(['ok' => false, 'locked' => true, 'review' => true, 'msg' => 'We could not verify you automatically.']);
}

$action = $_GET['action'] ?? '';

// Client-side failures (no face found on the ID, camera/model errors) also count as an attempt
if ($action === 'fail') idv_fail('We could not process that photo. Please try again.', ($_POST['where'] ?? '') === 'face' ? 'face' : 'id');

// ── 1. ID text must match the appointment record ──────────────
if ($action === 'id') {
    $front  = mb_substr((string)($_POST['ocr_text'] ?? ''), 0, 8000);
    $back   = mb_substr((string)($_POST['ocr_back'] ?? ''), 0, 8000);
    $numRaw = mb_substr((string)($_POST['ocr_number'] ?? ''), 0, 300);   // NEW: digits-only crop OCR
    $all    = $front . "\n" . $back;

    if (strlen(preg_replace('/\s/', '', $all)) < 25 && strlen(preg_replace('/\D/', '', $numRaw)) < 6) {
        idv_fail('We could not read any text on this ID. Retake the photo closer, in good light, with no glare.', 'id');
    }

    $upper = mb_strtoupper($all);
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $upper)), fn($l) => $l !== ''));

    $norm = fn(string $s): array => preg_split('/\s+/', trim(preg_replace('/[^\p{L}\s]/u', ' ', mb_strtoupper($s))), -1, PREG_SPLIT_NO_EMPTY);
    $near = function (string $a, string $b): bool {
        if ($a === $b) return true;
        if (strlen($b) > 40) return false;
        $la = strlen($a); $tol = $la >= 8 ? 2 : ($la >= 5 ? 1 : 0);
        return $tol > 0 && levenshtein($a, $b) <= $tol;
    };

    // ---- structure of the card text
    $mrz    = idv_mrz_lines($lines);
    $labels = idv_label_scan($lines);

    // ---- digits-only crop (NEW)
    $numRuns  = idv_digit_runs($numRaw);
    $numPlain = preg_replace('/\D/', '', $numRaw);
    if (strlen($numPlain) >= 6 && strlen($numPlain) <= 10 && !in_array($numPlain, $numRuns, true)) $numRuns[] = $numPlain;

    // ---- names (printed text + MRZ name line SURNAME<<GIVEN<NAMES)
    $mrzNames = '';
    foreach ($mrz as $ml) {
        if (preg_match('/^([A-Z]+(?:<[A-Z]+)*)<<([A-Z]+(?:<[A-Z]+)*)/', $ml, $nm)) {
            $mrzNames .= ' ' . str_replace('<', ' ', $nm[1]) . ' ' . str_replace('<', ' ', $nm[2]);
        }
    }
    $nameTokens = array_values(array_filter($norm((string)$m['full_name']), fn($t) => mb_strlen($t) >= 3));
    if (!$nameTokens) idv_fail('Your registered name is too short to verify automatically. Please contact your manager.', 'id');
    $ocrTokens = $norm($all . ' ' . $mrzNames);

    $matched = 0;
    foreach ($nameTokens as $nt) foreach ($ocrTokens as $ot) { if ($near($nt, $ot)) { $matched++; break; } }
    $need = min(2, count($nameTokens));

    // ---- is it a Kenyan ID at all (old, 2nd gen or Maisha)
    $kenyan = false;
    foreach ($mrz as $ml) { if (preg_match('/^I[D<]KEN/', $ml) || strpos($ml, 'KEN') !== false) { $kenyan = true; break; } }
    if (!$kenyan) {
        foreach ($ocrTokens as $ot) {
            if (strlen($ot) < 5) continue;
            foreach (['KENYA', 'JAMHURI', 'REPUBLIC', 'KITAMBULISHO', 'MAISHA'] as $kw) {
                if ($near($kw, $ot)) { $kenyan = true; break 2; }
            }
        }
    }

    // ---- ID number: look for the stored number where an ID number should be
    $nid = preg_replace('/\D/', '', (string)$m['national_id']);
    $idNeeded = strlen($nid) >= 6;
    $idOk = true;
    $onlySerial = false;
    $nameOk = $matched >= $need && ($idNeeded || $matched === count($nameTokens));

    if ($idNeeded) {
        $idOk = false;

        // a) printed next to an ID NUMBER / MAISHA NAMBA label (exact, then 1 OCR slip)
        foreach ($labels['id'] as $run) {
            if (idv_close($run, $nid)) { $idOk = true; break; }
        }
        // a2) NEW: dedicated digits-only crop of the ID NUMBER area (exact, then 1 OCR slip).
        //     Skip a run that is clearly the card serial number.
        if (!$idOk) {
            foreach ($numRuns as $run) {
                if (in_array($run, $labels['serial'], true) && $run !== $nid) continue;
                if (idv_close($run, $nid)) { $idOk = true; break; }
            }
        }
        // b) inside the machine readable zone on the back (exact only)
        if (!$idOk) {
            foreach ($mrz as $ml) { if (strpos($ml, $nid) !== false) { $idOk = true; break; } }
        }
        // c) no ID label could be read at all: accept an exact number anywhere,
        //    as long as it is not the number printed next to SERIAL
        $allRuns = idv_digit_runs($all);
        if (!$idOk && !$labels['id']) {
            foreach ($allRuns as $run) {
                if ($run === $nid && !in_array($run, $labels['serial'], true)) { $idOk = true; break; }
            }
        }
        // c2) NEW: no ID label read and the name already matched: allow ONE OCR slip
        //     on a run of exactly the same length as the stored number.
        if (!$idOk && !$labels['id'] && $nameOk) {
            foreach ($allRuns as $run) {
                if (strlen($run) === strlen($nid) && !in_array($run, $labels['serial'], true) && levenshtein($run, $nid) <= 1) {
                    $idOk = true; break;
                }
            }
        }
        // d) the number only appears as the serial number: tell the user
        if (!$idOk && in_array($nid, $labels['serial'], true)) $onlySerial = true;
    }

    if (IDV_DEBUG && (!$kenyan || $onlySerial || !$nameOk || !$idOk)) {
        $mask = fn(string $d): string => strlen($d) > 3 ? str_repeat('*', strlen($d) - 3) . substr($d, -3) : $d;
        $dbg = [
            'ocr_front_chars'  => strlen(preg_replace('/\s/', '', $front)),
            'ocr_back_chars'   => strlen(preg_replace('/\s/', '', $back)),
            'ocr_number_runs'  => array_map($mask, $numRuns),
            'kenyan'           => $kenyan ? 'yes' : 'NO',
            'mrz_lines'        => count($mrz),
            'name_tokens_matched' => "$matched of need $need (record has " . count($nameTokens) . ')',
            'stored_id'        => $mask($nid) . ' (' . strlen($nid) . ' digits)',
            'id_label_numbers' => array_map($mask, $labels['id']),
            'serial_numbers'   => array_map($mask, $labels['serial']),
            'all_digit_runs'   => array_map($mask, idv_digit_runs($all)),
            'id_ok'            => $idOk ? 'yes' : 'NO',
            'lines_with_digits' => array_values(array_map(
                fn($l) => mb_substr(preg_replace('/\d/', '#', $l), 0, 60),
                array_filter($lines, fn($l) => preg_match('/\d/', $l))
            )),
        ];
        idv_fail('DEBUG ' . json_encode($dbg), 'id');
    }
    if (!$kenyan) idv_fail('This does not look like a Kenyan National ID. Photograph the front of your ID (and the back if it is a Maisha Card).', 'id');
    if ($onlySerial) idv_fail('We found the card serial number but not your ID number. Make sure the line marked ID NUMBER (or MAISHA NAMBA) is clearly visible.', 'id');
    if (!$nameOk || !$idOk) idv_fail("We couldn't match this ID to your appointment record. Make sure it is your own ID with the name and ID number clearly visible. Maisha Card holders can also scan the back of the card.", 'id');

    $_SESSION['idv'] = ['nonce' => bin2hex(random_bytes(16)), 'id_ok' => time()];
    out(['ok' => true, 'nonce' => $_SESSION['idv']['nonce']]);
}

// ── 2. Live face must match the ID portrait ───────────────────
if ($action === 'face') {
    $s = $_SESSION['idv'] ?? null;
    if (!$s || empty($s['id_ok']) || (time() - (int)$s['id_ok']) > IDV_ID_FRESH) {
        out(['ok' => false, 'where' => 'id', 'redo_id' => true, 'msg' => 'Please scan your ID again.']);
    }
    if (!hash_equals((string)$s['nonce'], (string)($_POST['nonce'] ?? ''))) {
        idv_fail('Verification session mismatch. Please scan your ID again.', 'id');
    }
    $live = json_decode((string)($_POST['live'] ?? ''), true);
    $d    = json_decode((string)($_POST['d'] ?? ''), true);
    if (!is_array($live) || !is_array($d)) idv_fail('Invalid verification data. Please try again.', 'face');

    $ms = (int)($live['ms'] ?? 0); $frames = (int)($live['frames'] ?? 0);
    if (empty($live['a']) || empty($live['b']) || $ms < 2500 || $ms > 150000 || $frames < 8) {
        idv_fail('We could not confirm a live person. Please follow the on-screen instructions and try again.', 'face');
    }

    $idv = idv_vec($d['id'] ?? null);
    $sel = [];
    foreach ((array)($d['selfies'] ?? []) as $v) { $vv = idv_vec($v); if ($vv) $sel[] = $vv; }
    if (!$idv || count($sel) < 3 || count($sel) > 6) idv_fail('Invalid face data. Please try again.', 'face');

    $dists = array_map(fn($v) => idv_dist($idv, $v), $sel);
    $best = min($dists); $avg = array_sum($dists) / count($dists);

    if ($best < IDV_SAME_IMAGE) idv_fail('That looks like the same picture, not a live face. Please use your camera.', 'face');
    if ($best > IDV_FACE_BEST || $avg > IDV_FACE_AVG) {
        idv_fail('The face on the ID does not match your live face. Retry in good light, facing the camera, without a cap or glasses.', 'face');
    }

    unset($_SESSION['idv']);              // one use only
    $_SESSION['idv_fail']   = 0;
    $_SESSION['reg_idv_ok'] = time();     // the ONLY thing we keep: a pass flag
    out(['ok' => true]);
}

out(['ok' => false, 'msg' => 'Unknown action.']);