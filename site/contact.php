<?php
// © 2026 Conrad & Claude · HostFactor (hostfactor.co.uk). HostTally is proprietary software. All rights reserved. Copying, modifying, redistributing or reusing this code without written permission is not permitted.
// Contact form handler for the HostTally / HostFactor home page.
// ── Settings ─────────────────────────────────────────────
$TO      = 'billing@hostfactor.co.uk';      // where messages are sent
$FROM    = 'noreply@hosttally.co.uk';      // must be an address on your own domain
$SUBJECT = 'Website enquiry';               // the topic is added after this
// ─────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function reply(bool $ok, string $err = '', int $code = 200): void {
    http_response_code($code);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $err]);
    exit;
}
// ── Sales-spam filter ────────────────────────────────────
// Scores a message for the usual "we can get you 20 new clients" pitches.
// 5+ points: silently dropped (the sender is still told it was sent).
// 2-4 points: delivered, but the subject starts with [Possible spam].
function spam_score(string $name, string $email, string $tel, string $msg): int {
    $s = 0;
    $t = mb_strtolower("$name $email $msg");
    $t = str_replace(['“', '”', '‘', '’'], ['"', '"', "'", "'"], $t);
    $phrases = ['white-hat', 'white hat', 'black-hat', 'new clients', 'more clients', 'eager for your services', '10 to 20', '10-20 new',
        'digital marketing', 'marketing manager', 'marketing executive', 'business development manager', 'web solution', 'websolution',
        'seo services', 'seo expert', 'seo agency', 'seo specialist', 'first page of google', 'page 1 of google', 'top of google', 'rank your website',
        'backlink', 'guest post', 'link building', 'lead generation', 'qualified leads', 'boost your traffic', 'increase your sales',
        'website redesign', 'redesign your website', 'outsourc', 'offshore team', 'virtual assistant', 'crypto', 'bitcoin', 'casino', 'forex',
        'not interested', 'unsubscribe', 'opt out', 'opt-out', 'whatsapp me', 'telegram', 'learning more about this', 'free audit of your website'];
    foreach ($phrases as $p) if (strpos($t, $p) !== false) $s++;
    if (preg_match('/(send|reply)( us| with| back)?\s+["\']?no\b/', $t)) $s += 2;          // "send us No"
    $local = strtolower(strstr($email, '@', true) ?: '');
    if (preg_match('/(seo|web|digital|marketing|solution|leads?|growth|rank|agency|promo)/', $local)) $s += 2;
    if (preg_match('/^\s*(hello|hi|dear|greetings)[\s,]+(host ?tally|host ?factor)(\.co\.uk)?\b/i', $msg)) $s += 2; // greets the website, not a person
    $d = preg_replace('/\D/', '', $tel);
    if ($d !== '') {
        if (strlen($d) < 10 || strlen($d) > 13) $s++;
        if (preg_match('/^(\d{2,4})\1{2,}\d{0,3}$/', $d) || preg_match('/(\d)\1{5,}/', $d)) $s += 2; // 1201201200, 0000000
        if (!preg_match('/^(0|44)/', $d)) $s++;                                                    // not a UK number
    }
    if (substr_count(strtolower($msg), 'http') > 2) $s += 2;
    return $s;
}
// ─────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(false, 'Please use the form on the website.', 405);

// Spam traps: hidden field must be empty; simple per-visitor rate limit
if (!empty($_POST['website']) || !empty($_POST['fax_number'])) reply(true);
// the page's script adds a token and a timer; bots posting directly don't have them
if (strpos((string)($_POST['hf_js'] ?? ''), 'ok-') !== 0) reply(true);
if ((int)($_POST['elapsed'] ?? 0) < 3000) reply(true);
session_start();
$now = time();
$_SESSION['hf_sent'] = array_values(array_filter($_SESSION['hf_sent'] ?? [], fn($t) => $t > $now - 3600));
if (count($_SESSION['hf_sent']) >= 5) reply(false, 'You\'ve sent several messages already. Please try again a little later.', 429);

$clean = fn($v, $max) => trim(substr(str_replace(["\r", "\0"], '', (string)$v), 0, $max));
$name    = str_replace("\n", ' ', $clean($_POST['name'] ?? '', 100));
$tel     = preg_replace('/[^0-9+()\- ]/', '', $clean($_POST['tel'] ?? '', 40));
$email   = $clean($_POST['email'] ?? '', 200);
$topic   = str_replace("\n", ' ', $clean($_POST['topic'] ?? '', 80));
$message = $clean($_POST['message'] ?? '', 5000);

if ($name === '' || $message === '') reply(false, 'Please fill in your name and message.', 400);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) reply(false, 'Please enter a valid email address.', 400);
if (substr_count(strtolower($message), 'http') > 3) reply(true);

$body = "Name: $name\nTelephone: " . ($tel !== '' ? $tel : 'not given') . "\nEmail: $email\nAbout: $topic\n\n$message\n\n---\nSent from the hosttally.co.uk contact form on " . date('j M Y, H:i');
$score = spam_score($name, $email, $tel, $message);
if ($score >= 5) reply(true);
$subject = $SUBJECT . ($topic !== '' ? ': ' . $topic : '');
if ($score >= 2) $subject = '[Possible spam] ' . $subject;
$subject = preg_match('/[^\x20-\x7E]/', $subject) ? '=?UTF-8?B?' . base64_encode($subject) . '?=' : $subject;
$headers = "From: HostFactor Website <$FROM>\r\nReply-To: $email\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit";

if (!@mail($TO, $subject, $body, $headers, '-f' . $FROM)) {
    reply(false, 'The message couldn\'t be sent right now. Please try again in a few minutes.', 500);
}
$_SESSION['hf_sent'][] = $now;
reply(true);
