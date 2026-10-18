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
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(false, 'Please use the form on the website.', 405);

// Spam traps: hidden field must be empty; simple per-visitor rate limit
if (!empty($_POST['website'])) reply(true);
session_start();
$now = time();
$_SESSION['hf_sent'] = array_values(array_filter($_SESSION['hf_sent'] ?? [], fn($t) => $t > $now - 3600));
if (count($_SESSION['hf_sent']) >= 5) reply(false, 'You\'ve sent several messages already. Please email ' . $TO . ' directly instead.', 429);

$clean = fn($v, $max) => trim(substr(str_replace(["\r", "\0"], '', (string)$v), 0, $max));
$name    = str_replace("\n", ' ', $clean($_POST['name'] ?? '', 100));
$tel     = preg_replace('/[^0-9+()\- ]/', '', $clean($_POST['tel'] ?? '', 40));
$email   = $clean($_POST['email'] ?? '', 200);
$topic   = str_replace("\n", ' ', $clean($_POST['topic'] ?? '', 80));
$message = $clean($_POST['message'] ?? '', 5000);

if ($name === '' || $message === '') reply(false, 'Please fill in your name and message.', 400);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) reply(false, 'Please enter a valid email address.', 400);

$body = "Name: $name\nTelephone: " . ($tel !== '' ? $tel : 'not given') . "\nEmail: $email\nAbout: $topic\n\n$message\n\n---\nSent from the hosttally.co.uk contact form on " . date('j M Y, H:i');
$subject = $SUBJECT . ($topic !== '' ? ': ' . $topic : '');
$subject = preg_match('/[^\x20-\x7E]/', $subject) ? '=?UTF-8?B?' . base64_encode($subject) . '?=' : $subject;
$headers = "From: HostFactor Website <$FROM>\r\nReply-To: $email\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit";

if (!@mail($TO, $subject, $body, $headers, '-f' . $FROM)) {
    reply(false, 'The message couldn\'t be sent right now. Please email ' . $TO . ' instead.', 500);
}
$_SESSION['hf_sent'][] = $now;
reply(true);
