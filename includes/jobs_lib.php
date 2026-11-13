<?php
// includes/jobs_lib.php — shared by index.php (public) and staff_portal.php
const JB_TYPES = ['full_time', 'part_time', 'contract', 'internship', 'casual'];
const JB_INJECTION = '/ignore\s+(all\s+|any\s+)?(previous|prior|above)\s+(instructions|prompts)|disregard\s+(the\s+)?(above|previous|prior)|system\s+prompt|you\s+are\s+now\s+(a|an|the)\b|score\s+(this|me)\s+(a\s+)?(10|100)|rank\s+me\s+(first|#?1)/i';
const JB_DISCRIM   = '/\b(male|female|men|women|ladies|girls|boys)\s+only\b|\bage[d]?\s*(between\s*)?\d{2}|\b(below|under|above|over)\s+\d{2}\s*(years|yrs)\b|\bmust\s+be\s+(single|married|christian|muslim)\b|\b(christians?|muslims?)\s+only\b|\btribe\b/i';

function jb_age(?string $dob): ?int {
    if (!$dob) return null;
    try { return (new DateTime($dob))->diff(new DateTime('today'))->y; } catch (Throwable $e) { return null; }
}
function jb_list($v): array {
    if (is_array($v)) $a = $v;
    else { $a = json_decode((string)$v, true); if (!is_array($a)) $a = preg_split('/\s*[,;\n]\s*/', (string)$v, -1, PREG_SPLIT_NO_EMPTY); }
    $a = array_map(fn($x) => mb_substr(trim((string)$x), 0, 80), $a);
    return array_slice(array_values(array_unique(array_filter($a, 'strlen'))), 0, 25);
}
function jb_norm(string $s): string { return preg_replace('/[^a-z0-9+#. ]/', '', mb_strtolower($s)); }

function jb_certs_parse(string $t): array {
    $o = [];
    foreach (preg_split('/\R/', $t) as $l) {
        $l = trim($l); if ($l === '') continue;
        $p = array_map('trim', explode('|', $l));
        $y = (int)preg_replace('/\D/', '', $p[2] ?? '');
        $o[] = ['name' => mb_substr($p[0], 0, 120), 'issuer' => mb_substr($p[1] ?? '', 0, 100), 'year' => $y ?: null];
        if (count($o) >= 10) break;
    }
    return $o;
}
function jb_certs_lines($j): string {
    $a = json_decode((string)$j, true) ?: [];
    return implode("\n", array_map(function ($c) {
        $s = $c['name'] ?? '';
        if (!empty($c['issuer']) || !empty($c['year'])) $s .= ' | ' . ($c['issuer'] ?? '');
        if (!empty($c['year'])) $s .= ' | ' . $c['year'];
        return $s;
    }, $a));
}

function jb_complete(array $c): array {
    $sk = count(jb_list($c['skills'] ?? ''));
    $links = json_decode((string)($c['links'] ?? ''), true) ?: [];
    $items = [
        [10, !empty($c['photo_path']), 'Add a profile photo'],
        [5,  mb_strlen(trim($c['headline'] ?? '')) >= 8, 'Write a headline'],
        [10, mb_strlen(trim($c['bio'] ?? '')) >= 40, 'Write a short About me'],
        [10, !empty($c['dob']), 'Add your date of birth'],
        [5,  trim($c['location'] ?? '') !== '', 'Add your town'],
        [5,  !empty($c['phone']), 'Add your phone number'],
        [15, $sk >= 3, 'List at least 3 skills'],
        [15, !empty($c['cv_path']) || mb_strlen($c['cv_text'] ?? '') > 80, 'Upload your CV'],
        [10, mb_strlen(trim($c['work_history'] ?? '')) >= 20 || (int)($c['experience_years'] ?? 0) > 0, 'Add your work experience'],
        [5,  mb_strlen(trim($c['education'] ?? '')) >= 5, 'Add your education'],
        [5,  count(array_filter($links)) >= 1, 'Add a social or portfolio link'],
        [5,  mb_strlen(trim($c['interests'] ?? '')) >= 3, 'Add your interests'],
    ];
    $p = 0; $todo = [];
    foreach ($items as $i) { if ($i[1]) $p += $i[0]; else $todo[] = ['w' => $i[0], 't' => $i[2]]; }
    return ['pct' => $p, 'todo' => $todo];
}

// Deterministic match score. AI never decides this, it only explains it.
function jb_match(array $c, array $j): array {
    $need = jb_list($j['skills'] ?? '');
    $have = array_map('jb_norm', jb_list($c['skills'] ?? ''));
    $blob = jb_norm(($c['headline'] ?? '') . ' ' . ($c['interests'] ?? '') . ' ' . ($c['work_history'] ?? '') . ' ' . mb_substr($c['cv_text'] ?? '', 0, 6000));
    $matched = []; $missing = [];
    foreach ($need as $s) {
        $n = jb_norm($s); $hit = false;
        if ($n !== '') {
            foreach ($have as $h) { if ($h !== '' && (str_contains($h, $n) || str_contains($n, $h))) { $hit = true; break; } }
            if (!$hit && mb_strlen($n) >= 3 && str_contains($blob, $n)) $hit = true;
        }
        if ($hit) $matched[] = $s; else $missing[] = $s;
    }
    $skillPct = $need ? count($matched) / count($need) * 100 : 50;
    $minExp = (int)($j['min_exp'] ?? 0); $exp = (int)($c['experience_years'] ?? 0);
    $expPct = $minExp > 0 ? min(100, $exp / $minExp * 100) : 100;
    $jl = jb_norm($j['location'] ?? ''); $cl = jb_norm($c['location'] ?? '');
    $locPct = ($jl === '' || ($cl !== '' && (str_contains($jl, $cl) || str_contains($cl, $jl)))) ? 100 : 35;
    $comp = jb_complete($c)['pct'];
    $score = (int)round($skillPct * .55 + $expPct * .20 + $locPct * .10 + $comp * .15);
    return ['score' => max(0, min(100, $score)), 'matched' => $matched, 'missing' => $missing];
}

// Fixed rules. 'hard' = automatic rejection, 'soft' = flagged for a human.
function jb_rule_flags(PDO $pdo, array $c, array $job, ?array $app = null): array {
    $f = []; $age = jb_age($c['dob'] ?? null); $exp = (int)($c['experience_years'] ?? 0);
    if ($age !== null && $age < 18)
        $f[] = ['sev' => 'hard', 'code' => 'UNDERAGE', 'text' => 'The date of birth on the profile shows the applicant is under 18, the minimum age for employment.'];
    if ($age !== null && $exp > max(0, $age - 15))
        $f[] = ['sev' => 'hard', 'code' => 'IMPOSSIBLE_EXP', 'text' => "The profile claims {$exp} years of experience, which is not possible for the date of birth given."];
    $blob = ($c['bio'] ?? '') . ' ' . ($c['cv_text'] ?? '') . ' ' . ($c['work_history'] ?? '') . ' ' . ($app['cover_letter'] ?? '');
    if (preg_match(JB_INJECTION, $blob))
        $f[] = ['sev' => 'hard', 'code' => 'MANIPULATION', 'text' => 'The application contains text written to manipulate the screening system.'];
    $id = (int)($c['id'] ?? 0);
    if (!empty($c['cv_hash'])) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM jb_candidates WHERE cv_hash = ? AND id <> ?"); $q->execute([$c['cv_hash'], $id]);
        if ((int)$q->fetchColumn() > 0) $f[] = ['sev' => 'soft', 'code' => 'DUP_CV', 'text' => 'An identical CV is used by another account.'];
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM jb_candidates WHERE phone = ? AND id <> ?"); $q->execute([$c['phone'] ?? '', $id]);
    if ((int)$q->fetchColumn() > 0) $f[] = ['sev' => 'soft', 'code' => 'DUP_PHONE', 'text' => 'The same phone number is registered on another account.'];
    $dom = strtolower(substr(strrchr((string)($c['email'] ?? ''), '@') ?: '', 1));
    if (in_array($dom, ['mailinator.com', 'guerrillamail.com', 'yopmail.com', '10minutemail.com', 'trashmail.com', 'tempmail.com', 'sharklasers.com'], true))
        $f[] = ['sev' => 'soft', 'code' => 'DISPOSABLE_EMAIL', 'text' => 'The applicant used a disposable email address.'];
    foreach (json_decode((string)($c['certifications'] ?? ''), true) ?: [] as $ce) {
        if (!empty($ce['year']) && (int)$ce['year'] > (int)date('Y')) { $f[] = ['sev' => 'soft', 'code' => 'FUTURE_CERT', 'text' => 'A certification is dated in the future: ' . ($ce['name'] ?? '')]; break; }
    }
    if (!preg_match('/\p{L}+\s+\p{L}+/u', (string)($c['full_name'] ?? '')) || preg_match('/\d/', (string)($c['full_name'] ?? '')))
        $f[] = ['sev' => 'soft', 'code' => 'SUSPECT_NAME', 'text' => 'The name looks incomplete or invalid.'];
    if (empty($c['cv_path']) && mb_strlen($c['cv_text'] ?? '') < 80 && count(jb_list($c['skills'] ?? '')) < 3)
        $f[] = ['sev' => 'soft', 'code' => 'LOW_PROFILE', 'text' => 'No CV and fewer than 3 skills listed.'];
    $q = $pdo->prepare("SELECT COUNT(*) FROM jb_applications WHERE candidate_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"); $q->execute([$id]);
    if ((int)$q->fetchColumn() > 15) $f[] = ['sev' => 'soft', 'code' => 'SPAM', 'text' => 'More than 15 applications in the last 24 hours.'];
    return $f;
}

// Public-site AI helper (Groq first, Gemini fallback). Staff side uses ai_chat() instead.
function jb_ai(string $sys, string $user, bool $json = true, int $max = 1200): array {
    $prov = [];
    if (defined('GROQ_API_KEY') && GROQ_API_KEY !== '' && strpos(GROQ_API_KEY, 'PASTE_') !== 0)
        $prov[] = ['https://api.groq.com/openai/v1/chat/completions', GROQ_API_KEY, defined('GROQ_MODEL') ? GROQ_MODEL : 'llama-3.3-70b-versatile'];
    if (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '' && strpos(GEMINI_API_KEY, 'PASTE_') !== 0)
        $prov[] = ['https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', GEMINI_API_KEY, defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.5-flash'];
    if (!$prov) return ['ok' => false, 'msg' => 'AI is not configured yet.'];
    foreach ($prov as [$url, $key, $model]) {
        $p = ['model' => $model, 'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]],
              'temperature' => 0.3, 'max_tokens' => max($max, 1500)];
        if ($json) $p['response_format'] = ['type' => 'json_object'];
        if (strpos($url, 'groq') !== false && stripos($model, 'gpt-oss') !== false) $p['reasoning_effort'] = 'low';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($p),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key], CURLOPT_TIMEOUT => 28, CURLOPT_SSL_VERIFYPEER => true]);
        $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $d = json_decode((string)$resp, true);
        $text = trim((string)($d['choices'][0]['message']['content'] ?? ''));
        if ($code < 200 || $code >= 300 || $text === '') { error_log('[NYMIX JOBS AI] HTTP ' . $code . ' ' . substr((string)$resp, 0, 200)); continue; }
        if ($json) {
            $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text));
            $data = json_decode($text, true);
            if (!is_array($data)) continue;
            return ['ok' => true, 'data' => $data, 'text' => $text];
        }
        return ['ok' => true, 'data' => null, 'text' => $text];
    }
    return ['ok' => false, 'msg' => 'AI is busy right now. Try again in a minute.'];
}