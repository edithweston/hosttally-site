<?php
// © 2026 Conrad & Claude · HostFactor (hostfactor.co.uk). HostTally is proprietary software. All rights reserved. Copying, modifying, redistributing or reusing this code without written permission is not permitted.
// HostTally: server API. No changes needed here; settings live in config.php.
declare(strict_types=1);
require __DIR__ . '/config.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

const COLLECTIONS = ['expenses', 'stays', 'bills', 'config'];
const ID_RE = '/^[A-Za-z0-9_\-.~:@+]{1,200}$/';

function out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $code = 400, string $key = 'error'): void { out(['error' => $msg, 'code' => $key], $code); }

function data_dir(): string {
    $d = rtrim(DATA_DIR, '/');
    foreach ([$d, "$d/receipts", "$d/sessions"] as $p) {
        if (!is_dir($p) && !@mkdir($p, 0750, true)) fail('The data folder could not be created. Check folder permissions on your hosting.', 500, 'setup');
    }
    if (!file_exists("$d/.htaccess")) @file_put_contents("$d/.htaccess", "Require all denied\nDeny from all\n");
    if (!file_exists("$d/index.html")) @file_put_contents("$d/index.html", '');
    return $d;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) fail('Your hosting needs the PDO SQLite extension switched on (cPanel → Select PHP Version → Extensions → pdo_sqlite).', 500, 'setup');
    $pdo = new PDO('sqlite:' . data_dir() . '/upkeep.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, email TEXT UNIQUE NOT NULL, name TEXT NOT NULL DEFAULT "", pass TEXT NOT NULL, created INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS docs (user_id INTEGER NOT NULL, coll TEXT NOT NULL, id TEXT NOT NULL, data TEXT NOT NULL, updated INTEGER NOT NULL, PRIMARY KEY (user_id, coll, id))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS receipts (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, mime TEXT NOT NULL, created INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS attempts (ip TEXT NOT NULL, t INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS resets (token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL, expires INTEGER NOT NULL)');
    return $pdo;
}

function start_session(): void {
    $dir = data_dir() . '/sessions';
    session_save_path($dir);
    $life = 60 * 60 * 24 * 120; // stay signed in for about 4 months
    ini_set('session.gc_maxlifetime', (string)$life);
    ini_set('session.use_strict_mode', '1');
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('upkeep_sid');
    session_set_cookie_params(['lifetime' => $life, 'path' => $path, 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

function uid(): int {
    $id = (int)($_SESSION['uid'] ?? 0);
    if ($id <= 0) fail('Please sign in again.', 401, 'signed_out');
    return $id;
}

function body(): array {
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 400000) fail('That request is too large.', 413);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

// Weekly snapshot of the database into data/backups (keeps the latest 8).
function auto_backup(): void {
    try {
        $dir = data_dir() . '/backups';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $files = glob($dir . '/upkeep-*.sqlite') ?: [];
        sort($files);
        $last = $files ? filemtime(end($files)) : 0;
        if ($last && $last > time() - 7 * 86400) return;
        if (!is_file(data_dir() . '/upkeep.sqlite')) return;
        $lock = @fopen($dir . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
        $dest = $dir . '/upkeep-' . date('Y-m-d') . '.sqlite';
        if (!is_file($dest)) {
            try { db()->exec('VACUUM INTO ' . db()->quote($dest)); }
            catch (Throwable $e) { @copy(data_dir() . '/upkeep.sqlite', $dest); } // older SQLite: plain copy
        }
        $files = glob($dir . '/upkeep-*.sqlite') ?: [];
        sort($files);
        while (count($files) > 8) @unlink(array_shift($files));
        flock($lock, LOCK_UN); fclose($lock);
    } catch (Throwable $e) { error_log('HostTally backup: ' . $e->getMessage()); }
}
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64); }
function too_many_attempts(): bool {
    $db = db();
    $db->prepare('DELETE FROM attempts WHERE t < ?')->execute([time() - 900]);
    $s = $db->prepare('SELECT COUNT(*) FROM attempts WHERE ip = ?');
    $s->execute([client_ip()]);
    return (int)$s->fetchColumn() >= 10;
}
function note_attempt(): void { db()->prepare('INSERT INTO attempts (ip, t) VALUES (?, ?)')->execute([client_ip(), time()]); }

function user_info(int $id): ?array {
    $s = db()->prepare('SELECT email, name FROM users WHERE id = ?');
    $s->execute([$id]);
    $u = $s->fetch();
    if (!$u) return null;
    if ($u['email'] === 'demo@upkeep-ledger.demo') { $u['demo'] = true; $u['email'] = 'Demo account'; }
    return $u;
}

// ─────────────────────────────────────────────────────────
//  Demo account (shared, sample data, resets nightly)
// ─────────────────────────────────────────────────────────
// The app's name. Uses APP_NAME from config.php unless it still says the old name.
function brand_name(): string { return (defined('APP_NAME') && APP_NAME !== '' && APP_NAME !== 'Upkeep Ledger') ? APP_NAME : 'HostTally'; }

const DEMO_EMAIL = 'demo@upkeep-ledger.demo';
function demo_on(): bool { return defined('DEMO_ENABLED') && DEMO_ENABLED; }
function is_demo_uid(int $id): bool {
    $u = $id ? user_info_raw($id) : null;
    return $u && $u['email'] === DEMO_EMAIL;
}
function user_info_raw(int $id): ?array {
    $s = db()->prepare('SELECT email, name FROM users WHERE id = ?');
    $s->execute([$id]);
    $u = $s->fetch();
    return $u ?: null;
}
function demo_uid(): int {
    $s = db()->prepare('SELECT id FROM users WHERE email = ?');
    $s->execute([DEMO_EMAIL]);
    $id = (int)$s->fetchColumn();
    if ($id) return $id;
    db()->prepare('INSERT INTO users (email, name, pass, created) VALUES (?, ?, ?, ?)')
        ->execute([DEMO_EMAIL, 'Demo', password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), time()]);
    return (int)db()->lastInsertId();
}
function demo_reset(int $uid): void {
    $db = db();
    $db->beginTransaction();
    // remove the demo's own uploads, keep the bundled sample photos
    $samples = ['supermarket' => md5('upkeep-demo-supermarket'), 'laundrette' => md5('upkeep-demo-laundrette')];
    $s = $db->prepare('SELECT id FROM receipts WHERE user_id = ?');
    $s->execute([$uid]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        if (!in_array($rid, $samples, true)) @unlink(data_dir() . '/receipts/' . $rid);
    }
    $db->prepare('DELETE FROM receipts WHERE user_id = ?')->execute([$uid]);
    $db->prepare('DELETE FROM docs WHERE user_id = ?')->execute([$uid]);
    foreach ($samples as $name => $rid) {
        $src = __DIR__ . '/demo/receipt-' . $name . '.jpg';
        $dst = data_dir() . '/receipts/' . $rid;
        if (is_file($src)) { @copy($src, $dst); $db->prepare('INSERT OR REPLACE INTO receipts (id, user_id, mime, created) VALUES (?, ?, ?, ?)')->execute([$rid, $uid, 'image/jpeg', time()]); }
    }
    $d = fn(int $off) => date('Y-m-d', strtotime(($off >= 0 ? '+' : '') . $off . ' days'));
    $put = function (string $coll, string $id, array $data) use ($db, $uid) {
        $db->prepare('INSERT OR REPLACE INTO docs (user_id, coll, id, data, updated) VALUES (?, ?, ?, ?, ?)')
           ->execute([$uid, $coll, $id, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time()]);
    };
    $now = (int)(microtime(true) * 1000);
    $A = 'Seaview Cottage'; $B = 'Harbour Flat';
    $put('config', 'properties', ['list' => [$A, $B]]);
    $put('config', 'onboarding', ['done' => true, 'at' => $now]);
    $put('config', 'demoreset', ['day' => date('Y-m-d'), 'v' => 6]);
    $stays = [
        ['s1', $A, 'The Patel family', -30, -25, 61500], ['s2', $A, 'Sarah & Tom', -2, 3, 58200], ['s3', $A, 'The Okafors', 10, 14, 0],
        ['s4', $B, 'James Wright', -8, -4, 42800], ['s5', $B, 'Lucy Chen', 5, 9, 0],
    ];
    $st = [];
    foreach ($stays as [$id, $p, $g, $a, $b, $inc]) { $st[$id] = [$p, $g, $d($a), $d($b)]; $put('stays', 'demo-' . $id, ['property' => $p, 'guest' => $g, 'start' => $d($a), 'end' => $d($b), 'income' => $inc, 'createdAt' => $now]); }
    $rcpt = $samples;
    $ex = [
        // [stay, day offset, category, item, shop, paid, pence, receipt]
        ['s2', -2, 'welcome', 'Milk, tea, bread, butter, biscuits', 'Corner Stores', 'Card', 975, 'supermarket'],
        ['s2', -2, 'cleaning', 'Washing up liquid', 'Corner Stores', 'Card', 125, 'supermarket'],
        ['s2', -1, 'laundry', 'Large wash, bedding', 'Suds Laundrette', 'Cash', 600, 'laundrette'],
        ['s2', -1, 'laundry', 'Dryer 30 + 20 min', 'Suds Laundrette', 'Cash', 700, 'laundrette'],
        ['s2', -3, 'toiletries', 'Shower gel, shampoo, soap', 'Amazon', 'Card', 840, null],
        ['s1', -31, 'welcome', 'Welcome hamper and wine', 'Farm Shop', 'Card', 1890, null],
        ['s1', -24, 'laundry', 'Two loads, towels', 'Suds Laundrette', 'Cash', 1200, null],
        ['s1', -24, 'replacement', 'Replacement wine glasses x4', 'Dunelm', 'Card', 1600, null],
        ['s1', -26, 'repairs', 'Kitchen tap washer replaced', 'J Smith Plumbing', 'Bank transfer', 4500, null],
        ['s1', -25, 'cleaner', 'Changeover clean', 'Sparkle Cleaning', 'Bank transfer', 4500, null],
        ['s2', 3, 'cleaner', 'Changeover clean', 'Sparkle Cleaning', 'Bank transfer', 4500, null],
        ['s4', -8, 'welcome', 'Coffee, milk, croissants', 'Corner Stores', 'Card', 1080, null],
        ['s4', -4, 'cleaning', 'Bathroom cleaner, cloths', 'Corner Stores', 'Card', 670, null],
        ['s4', -4, 'linen', 'Two bath towels', 'Dunelm', 'Card', 1800, null],
        ['s4', -5, 'other', 'Window cleaner, all windows', 'Mike F Windows', 'Cash', 2450, null],
    ];
    foreach ($ex as $i => [$sid, $off, $cat, $item, $shop, $paid, $p, $r]) {
        [$prop, $guest, $sa, $sb] = $st[$sid];
        $put('expenses', 'demo-e' . ($i + 1), [
            'amount' => $p, 'category' => $cat, 'date' => $d($off), 'note' => $item, 'shop' => $shop, 'paidBy' => $paid,
            'property' => $prop, 'guest' => $guest, 'stayStart' => $sa, 'stayEnd' => $sb,
            'createdAt' => $now - (count($ex) - $i) * 60000, 'receiptId' => $r ? $rcpt[$r] : null,
        ]);
    }
    // general (running) costs, not tied to a stay
    $gen = [[$A, -12, 'cleaning', 'Bulk cleaning stock: bleach, sprays, window cleaner', 'Costco', 'Card', 3860],
            [$A, -6, 'fuel', 'Fuel for changeover runs', 'Fuel station', 'Card', 4200],
            [$A, -20, 'toiletries', 'Bulk shampoo, shower gel and soap (24 of each)', 'Amazon', 'Card', 5499],
            [$B, -15, 'replacement', 'New kettle', 'Argos', 'Card', 2500]];
    foreach ($gen as $i => [$prop, $off, $cat, $item, $shop, $paid, $p]) {
        $put('expenses', 'demo-g' . ($i + 1), ['amount' => $p, 'category' => $cat, 'pot' => 'general', 'date' => $d($off), 'note' => $item, 'shop' => $shop, 'paidBy' => $paid,
            'property' => $prop, 'guest' => '', 'stayStart' => '', 'stayEnd' => '', 'createdAt' => $now - 3600000 * ($i + 1), 'receiptId' => null]);
    }
    // bills for Seaview Cottage
    $billPdf = md5('upkeep-demo-bill');
    if (is_file(__DIR__ . '/demo/bill-sample.pdf')) {
        @copy(__DIR__ . '/demo/bill-sample.pdf', data_dir() . '/receipts/' . $billPdf);
        $db->prepare('INSERT OR REPLACE INTO receipts (id, user_id, mime, created) VALUES (?, ?, ?, ?)')->execute([$billPdf, $uid, 'application/pdf', time()]);
    } else $billPdf = null;
    $y = (int)date('Y'); $mNow = (int)date('n');
    $types = [['electric', 'Electricity', 'kWh', [6800, 6400, 5900, 4800, 4100, 3600, 3500, 3700, 4200, 5100, 6100, 7000], [310, 290, 265, 210, 180, 150, 145, 155, 185, 230, 280, 320], 'Sample Energy Co'],
              ['gas', 'Gas', 'kWh', [9200, 8800, 7400, 5200, 3100, 2100, 1900, 2000, 2900, 5000, 7600, 9000], [1180, 1120, 940, 650, 380, 250, 230, 240, 350, 620, 960, 1150], 'Coastal Gas Co'],
              ['broadband', 'Broadband', '', array_fill(0, 12, 3200), array_fill(0, 12, 0), 'FibreNet']];
    $n = 0;
    foreach ($types as [$tid, $tname, $unit, $amts, $use, $sup]) {
        for ($m = 1; $m < $mNow; $m++) {
            if ($tid === 'gas' && $m === $mNow - 1) continue;   // one missing bill to show the gap
            $from = sprintf('%04d-%02d-01', $y, $m); $to = date('Y-m-t', strtotime($from));
            $put('bills', 'demo-b' . (++$n), ['property' => $A, 'type' => $tid, 'typeName' => $tname, 'from' => $from, 'to' => $to,
                'amount' => $amts[$m - 1], 'usage' => $use[$m - 1] ?: '', 'unit' => $unit, 'note' => $sup,
                'fileId' => ($tid === 'electric' && $billPdf) ? $billPdf : null, 'fileMime' => ($tid === 'electric' && $billPdf) ? 'application/pdf' : null, 'createdAt' => $now]);
        }
    }
    for ($q = 1; $q + 2 < $mNow; $q += 3) {
        $from = sprintf('%04d-%02d-01', $y, $q); $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $q + 2)));
        $put('bills', 'demo-b' . (++$n), ['property' => $A, 'type' => 'water', 'typeName' => 'Water', 'from' => $from, 'to' => $to, 'amount' => 11850, 'usage' => 21, 'unit' => 'm³', 'note' => 'Harbour Water', 'fileId' => null, 'createdAt' => $now]);
    }
    $put('bills', 'demo-b' . (++$n), ['property' => $A, 'type' => 'insurance', 'typeName' => 'Insurance', 'from' => sprintf('%04d-04-01', $y), 'to' => sprintf('%04d-03-31', $y + 1), 'amount' => 48600, 'usage' => '', 'unit' => '', 'note' => 'Holiday let policy', 'fileId' => $billPdf, 'fileMime' => $billPdf ? 'application/pdf' : null, 'createdAt' => $now]);
    $put('bills', 'demo-b' . (++$n), ['property' => $A, 'type' => 'counciltax', 'typeName' => 'Council tax', 'from' => sprintf('%04d-04-01', $y), 'to' => sprintf('%04d-03-31', $y + 1), 'amount' => 189200, 'usage' => '', 'unit' => '', 'note' => 'Band C', 'fileId' => null, 'createdAt' => $now]);
    $put('bills', 'demo-b' . (++$n), ['property' => $A, 'type' => 'tvlicence', 'typeName' => 'TV licence', 'from' => sprintf('%04d-02-01', $y), 'to' => sprintf('%04d-01-31', $y + 1), 'amount' => 17450, 'usage' => '', 'unit' => '', 'note' => '', 'fileId' => null, 'createdAt' => $now]);
    $db->commit();
}
function demo_needs_reset(int $uid): bool {
    $s = db()->prepare("SELECT data FROM docs WHERE user_id = ? AND coll = 'config' AND id = 'demoreset'");
    $s->execute([$uid]);
    $j = json_decode((string)$s->fetchColumn(), true);
    if (!is_array($j)) return true;                       // first time: load sample data
    if ((int)($j['v'] ?? 1) < 6) return true;              // sample data has new parts (bills)
    if (!defined('DEMO_RESET_NIGHTLY') || !DEMO_RESET_NIGHTLY) return false;
    return ($j['day'] ?? '') !== date('Y-m-d');
}

// ─────────────────────────────────────────────────────────
//  Airbnb calendar (iCal) import
// ─────────────────────────────────────────────────────────
function ical_url_ok(string $url): bool {
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https') return false;
    $host = strtolower($p['host'] ?? '');
    if (!preg_match('/(^|\.)airbnb\.[a-z.]{2,10}$/', $host)) return false;
    return (bool)preg_match('#^/calendar/ical/[0-9]+\.ics$#', $p['path'] ?? '');
}
function ical_fetch(string $url): string {
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => 'HostTally/1.0 (+calendar sync)',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS]);
        $body = curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        if ($body === false || $code >= 400) throw new RuntimeException("Airbnb didn't return the calendar (error $code). Check the link is still valid.");
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'HostTally/1.0 (+calendar sync)']]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) throw new RuntimeException("Couldn't reach Airbnb to read the calendar. Try again later.");
    }
    if (strlen($body) > 3000000) throw new RuntimeException('The calendar was unexpectedly large.');
    if (strpos($body, 'BEGIN:VCALENDAR') === false) throw new RuntimeException("That link didn't return a calendar. Copy it again from Airbnb.");
    return $body;
}
function ical_parse(string $ics): array {
    $ics = str_replace(["\r\n", "\r"], "\n", $ics);
    $ics = preg_replace("/\n[ \t]/", '', $ics);               // unfold long lines
    $out = [];
    if (!preg_match_all('/BEGIN:VEVENT\n(.*?)\nEND:VEVENT/s', $ics, $m)) return $out;
    foreach ($m[1] as $block) {
        $ev = [];
        foreach (explode("\n", $block) as $line) {
            if (!preg_match('/^([A-Z\-]+)((?:;[^:]*)?):(.*)$/', $line, $mm)) continue;
            $ev[$mm[1]] = str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $mm[3]);
        }
        $d = fn($v) => preg_match('/^(\d{4})(\d{2})(\d{2})/', $v ?? '', $x) ? "$x[1]-$x[2]-$x[3]" : '';
        $start = $d($ev['DTSTART'] ?? ''); $end = $d($ev['DTEND'] ?? '');
        $sum = trim($ev['SUMMARY'] ?? '');
        if (!$start || !$end || $end <= $start) continue;
        if (preg_match('/not available|blocked|unavailable/i', $sum)) continue;   // owner blocks, not bookings
        $desc = $ev['DESCRIPTION'] ?? '';
        $ref = preg_match('#/details/([A-Z0-9]{6,14})#', $desc, $r) ? $r[1] : '';
        $phone = preg_match('/Last 4 Digits\)?:?\s*(\d{4})/i', $desc, $r) ? $r[1] : '';
        $out[] = ['uid' => trim($ev['UID'] ?? ($start . $end)), 'start' => $start, 'end' => $end, 'ref' => $ref, 'phone4' => $phone, 'summary' => $sum];
    }
    return $out;
}
function docs_of(int $uid, string $coll): array {
    $s = db()->prepare('SELECT id, data FROM docs WHERE user_id = ? AND coll = ?');
    $s->execute([$uid, $coll]);
    $r = [];
    foreach ($s as $row) $r[$row['id']] = json_decode($row['data'], true) ?: [];
    return $r;
}
function put_doc(int $uid, string $coll, string $id, array $data): void {
    db()->prepare('INSERT OR REPLACE INTO docs (user_id, coll, id, data, updated) VALUES (?, ?, ?, ?, ?)')
        ->execute([$uid, $coll, $id, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time()]);
}
function ical_sync(int $uid, bool $force): array {
    $cfg = docs_of($uid, 'config');
    $list = $cfg['icals']['list'] ?? [];
    if (!$list) return ['added' => 0, 'updated' => 0, 'removed' => 0, 'errors' => [], 'skipped' => false];
    $last = (int)($cfg['icals']['syncedAt'] ?? 0);
    if (!$force && $last > time() - 3600) return ['added' => 0, 'updated' => 0, 'removed' => 0, 'errors' => [], 'skipped' => true];
    if ($force && $last > time() - 20) return ['added' => 0, 'updated' => 0, 'removed' => 0, 'errors' => [], 'skipped' => true];
    $stays = docs_of($uid, 'stays');
    $expenses = docs_of($uid, 'expenses');
    $today = date('Y-m-d');
    $now = (int)(microtime(true) * 1000);
    $tot = ['added' => 0, 'updated' => 0, 'removed' => 0, 'errors' => [], 'skipped' => false];
    db()->beginTransaction();
    foreach ($list as $i => $entry) {
        $prop = (string)($entry['property'] ?? ''); $url = (string)($entry['url'] ?? '');
        if ($prop === '' || !ical_url_ok($url)) { $list[$i]['error'] = 'This doesn\'t look like an Airbnb calendar link.'; continue; }
        try { $events = ical_parse(ical_fetch($url)); }
        catch (Throwable $e) { $list[$i]['error'] = $e->getMessage(); $tot['errors'][] = "$prop: " . $e->getMessage(); continue; }
        $seen = [];
        $byUid = [];
        foreach ($stays as $oid => $o) if (($o['property'] ?? '') === $prop && !empty($o['icalUid'])) $byUid[$o['icalUid']] = $oid;
        foreach ($events as $ev) {
            $sid = $byUid[$ev['uid']] ?? null;
            if ($sid === null) {
                // same dates already added by hand? link that one instead of duplicating
                foreach ($stays as $oid => $o) {
                    if (($o['property'] ?? '') === $prop && ($o['start'] ?? '') === $ev['start'] && ($o['end'] ?? '') === $ev['end'] && empty($o['icalUid'])) {
                        $o['source'] = 'airbnb'; $o['ref'] = $ev['ref']; $o['phone4'] = $ev['phone4']; $o['icalUid'] = $ev['uid'];
                        put_doc($uid, 'stays', $oid, $o); $stays[$oid] = $o; $seen[$oid] = true; $byUid[$ev['uid']] = $oid;
                        continue 2;
                    }
                }
                $sid = 'ab-' . substr(sha1($prop . '|' . $ev['uid'] . '|' . microtime()), 0, 16);
                $doc = ['property' => $prop, 'guest' => '', 'start' => $ev['start'], 'end' => $ev['end'], 'income' => 0,
                        'source' => 'airbnb', 'ref' => $ev['ref'], 'phone4' => $ev['phone4'], 'icalUid' => $ev['uid'], 'createdAt' => $now];
                put_doc($uid, 'stays', $sid, $doc); $stays[$sid] = $doc; $seen[$sid] = true; $byUid[$ev['uid']] = $sid; $tot['added']++;
                continue;
            }
            $seen[$sid] = true;
            $o = $stays[$sid];
            if (!empty($o['hidden'])) continue;                       // removed by the host: leave it alone
            if (($o['start'] ?? '') !== $ev['start'] || ($o['end'] ?? '') !== $ev['end'] || !empty($o['cancelled'])) {
                // dates changed on Airbnb: move the stay and its linked expenses
                foreach ($expenses as $eid => $x) {
                    if (($x['property'] ?? '') === $prop && ($x['stayStart'] ?? '') === ($o['start'] ?? '') && ($x['stayEnd'] ?? '') === ($o['end'] ?? '')) {
                        $x['stayStart'] = $ev['start']; $x['stayEnd'] = $ev['end']; put_doc($uid, 'expenses', $eid, $x); $expenses[$eid] = $x;
                    }
                }
                $o['start'] = $ev['start']; $o['end'] = $ev['end']; unset($o['cancelled']);
                $o['ref'] = $ev['ref'] ?: ($o['ref'] ?? ''); $o['phone4'] = $ev['phone4'] ?: ($o['phone4'] ?? '');
                put_doc($uid, 'stays', $sid, $o); $stays[$sid] = $o; $tot['updated']++;
            }
        }
        // future Airbnb bookings that have disappeared from the calendar were cancelled
        foreach ($stays as $oid => $o) {
            if (($o['property'] ?? '') !== $prop || empty($o['icalUid']) || isset($seen[$oid]) || !empty($o['cancelled']) || !empty($o['hidden'])) continue;
            if (($o['end'] ?? '') < $today) continue;
            $linked = false;
            foreach ($expenses as $x) if (($x['property'] ?? '') === $prop && ($x['stayStart'] ?? '') === ($o['start'] ?? '') && ($x['stayEnd'] ?? '') === ($o['end'] ?? '')) { $linked = true; break; }
            if ($linked || !empty($o['income'])) { $o['cancelled'] = true; put_doc($uid, 'stays', $oid, $o); $stays[$oid] = $o; }
            else { db()->prepare('DELETE FROM docs WHERE user_id = ? AND coll = ? AND id = ?')->execute([$uid, 'stays', $oid]); unset($stays[$oid]); }
            $tot['removed']++;
        }
        $list[$i]['lastSync'] = time(); $list[$i]['count'] = count($events); $list[$i]['error'] = '';
    }
    put_doc($uid, 'config', 'icals', ['list' => $list, 'syncedAt' => time()]);
    db()->commit();
    return $tot;
}

// ─────────────────────────────────────────────────────────
//  Emails
// ─────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────
//  Email reminders: missing monthly bills, yearly renewals
//  Runs at most once a day, when anyone uses the app.
// ─────────────────────────────────────────────────────────
function reminders_for(int $uid): array {
    $bills = docs_of($uid, 'bills');
    $items = [];
    $lastStart = date('Y-m-01', strtotime('first day of last month'));
    $lastEnd = date('Y-m-t', strtotime('first day of last month'));
    $lastName = date('F', strtotime('first day of last month'));
    $groups = [];
    foreach ($bills as $id => $b) {
        $from = (string)($b['from'] ?? ''); $to = (string)($b['to'] ?? $from);
        if ($from === '') continue;
        $b['id'] = $id; $b['days'] = (strtotime($to) - strtotime($from)) / 86400 + 1;
        $groups[($b['property'] ?? '') . '|' . ($b['type'] ?? '')][] = $b;
    }
    $log = docs_of($uid, 'config')['remindlog'] ?? [];
    $sent = $log['renewals'] ?? [];
    $soon = date('Y-m-d', strtotime('+21 days')); $today = date('Y-m-d');
    foreach ($groups as $list) {
        usort($list, fn($a, $b) => strcmp($a['from'], $b['from']));
        $first = $list[0]; $latest = end($list);
        $name = (string)($latest['typeName'] ?? 'Bill'); $prop = (string)($latest['property'] ?? '');
        $monthly = $latest['days'] <= 100;
        if ($monthly) {
            if ($first['from'] >= $lastStart) continue;               // started tracking recently
            $covered = false;
            foreach ($list as $b) if ($b['from'] <= $lastEnd && ($b['to'] ?? $b['from']) >= $lastStart) { $covered = true; break; }
            if (!$covered) $items[] = ['key' => '', 'text' => "$name bill for $lastName isn't added yet ($prop)"];
        } else {
            $end = (string)($latest['to'] ?? '');
            if ($end >= $today && $end <= $soon && !in_array($latest['id'], $sent, true)) {
                $items[] = ['key' => $latest['id'], 'text' => "$name for $prop ends on " . date('j M Y', strtotime($end)) . '. Add the renewal when it arrives'];
            }
        }
    }
    return $items;
}
function run_reminders(): void {
    try {
        if (!defined('MAIL_FROM') || MAIL_FROM === '') return;
        $flag = data_dir() . '/.reminders-day';
        if (is_file($flag) && trim((string)@file_get_contents($flag)) === date('Y-m-d')) return;
        $lock = @fopen(data_dir() . '/.reminders-lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
        @file_put_contents($flag, date('Y-m-d'));
        $users = db()->query('SELECT id, email, name FROM users')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as $u) {
            $id = (int)$u['id'];
            if (is_demo_uid($id)) continue;
            $cfg = docs_of($id, 'config');
            if (!empty($cfg['remindprefs']['off'])) continue;
            $log = $cfg['remindlog'] ?? ['month' => '', 'renewals' => []];
            $items = reminders_for($id);
            $monthKey = date('Y-m');
            $missing = array_values(array_filter($items, fn($i) => $i['key'] === ''));
            $renew = array_values(array_filter($items, fn($i) => $i['key'] !== ''));
            if ((int)date('j') < 5 || ($log['month'] ?? '') === $monthKey) $missing = [];   // monthly digest from the 5th, once a month
            if (!$missing && !$renew) continue;
            if (send_reminder((string)$u['email'], (string)$u['name'], array_merge($renew, $missing))) {
                if ($missing) $log['month'] = $monthKey;
                $log['renewals'] = array_slice(array_merge($log['renewals'] ?? [], array_map(fn($i) => $i['key'], $renew)), -200);
                put_doc($id, 'config', 'remindlog', $log);
            }
        }
        flock($lock, LOCK_UN); fclose($lock);
    } catch (Throwable $e) { error_log('HostTally reminders: ' . $e->getMessage()); }
}
function send_reminder(string $email, string $name, array $items): bool {
    $url = app_url(); $app = brand_name();
    $first = trim(explode(' ', trim($name))[0] ?? '');
    $hi = $first !== '' ? "Hi $first," : 'Hi there,';
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $n = count($items);
    $subject = $n === 1 ? "$app: 1 thing to check" : "$app: $n things to check";
    $text = "$hi\n\nA quick reminder from $app:\n\n";
    foreach ($items as $i) $text .= "- " . $i['text'] . "\n";
    $text .= "\nOpen the app: $url\n\nTo stop these emails: Settings > Email reminders.\n\nThe HostTally team\nhosttally.co.uk";
    $li = '';
    foreach ($items as $i) $li .= '<tr><td style="padding:0 0 10px 0;vertical-align:top;width:22px;color:#1E6670;font:bold 16px Arial,sans-serif">&bull;</td><td style="padding:0 0 10px 0;font:15px/1.45 Arial,sans-serif;color:#16262D">' . $e($i['text']) . '</td></tr>';
    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#EEF3F4">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF3F4"><tr><td align="center" style="padding:28px 12px">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:18px;border:1px solid #D6E0E3">'
      . '<tr><td style="padding:28px;font:15px/1.55 Arial,sans-serif;color:#16262D">'
      . '<div style="font:bold 22px Arial,sans-serif;margin:0 0 14px 0">' . ($n === 1 ? '1 thing to check' : $n . ' things to check') . '</div>'
      . '<p style="margin:0 0 16px 0">' . $e($hi) . '</p>'
      . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 14px 0">' . $li . '</table>'
      . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px 0"><tr><td style="border-radius:12px;background:#1E6670"><a href="' . $e($url) . '" style="display:inline-block;padding:14px 26px;font:bold 16px Arial,sans-serif;color:#ffffff;text-decoration:none">Open ' . $e($app) . '</a></td></tr></table>'
      . '<p style="margin:0;color:#5E7179;font-size:13px">To stop these emails, open Settings in the app and switch off Email reminders.</p>'
      . '</td></tr><tr><td style="padding:14px 28px;border-top:1px solid #D6E0E3;font:12px Arial,sans-serif;color:#8193A0">&copy; ' . date('Y') . ' Conrad &amp; Claude &middot; hosttally.co.uk</td></tr></table></td></tr></table></body></html>';
    return send_mail($email, $subject, $text, $html);
}
function app_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    return ($https ? 'https://' : 'http://') . $host . $path;
}
function mime_header(string $s): string {
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}
function send_mail(string $to, string $subject, string $text, string $html, string $replyTo = ''): bool {
    if (!defined('MAIL_FROM') || MAIL_FROM === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $b = 'ul' . bin2hex(random_bytes(8));
    $fromName = (defined('MAIL_FROM_NAME') && MAIL_FROM_NAME !== '' && MAIL_FROM_NAME !== 'Upkeep Ledger') ? MAIL_FROM_NAME : brand_name();
    $h  = 'From: ' . mime_header($fromName) . ' <' . MAIL_FROM . ">\r\n";
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $h .= "Reply-To: $replyTo\r\n";
    $h .= "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n";
    $body  = "--$b\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$text\r\n";
    $body .= "--$b\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$html\r\n--$b--\r\n";
    return @mail($to, mime_header($subject), $body, $h, '-f' . MAIL_FROM);
}
function send_reset(string $email, string $name, string $link): bool {
    $first = trim(explode(' ', trim($name))[0] ?? '');
    $hi = $first !== '' ? "Hi $first," : 'Hi there,';
    $app = brand_name();
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $text = "$hi\n\nSomeone asked to reset the password for your $app account ($email).\n\n"
          . "Choose a new password here (the link works for 1 hour):\n$link\n\n"
          . "If you didn't ask for this, you can ignore this email. Your password won't change.\n\n"
          . "The HostTally team\nhosttally.co.uk";
    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#EEF3F4">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF3F4"><tr><td align="center" style="padding:28px 12px">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:18px;border:1px solid #D6E0E3">'
      . '<tr><td style="padding:28px;font:15px/1.55 Arial,sans-serif;color:#16262D">'
      . '<div style="font:bold 22px Arial,sans-serif;margin:0 0 14px 0">Reset your password</div>'
      . '<p style="margin:0 0 12px 0">' . $e($hi) . '</p>'
      . '<p style="margin:0 0 20px 0">Someone asked to reset the password for your ' . $e($app) . ' account (<b>' . $e($email) . '</b>).</p>'
      . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px 0"><tr><td style="border-radius:12px;background:#1E6670"><a href="' . $e($link) . '" style="display:inline-block;padding:14px 26px;font:bold 16px Arial,sans-serif;color:#ffffff;text-decoration:none">Choose a new password</a></td></tr></table>'
      . '<p style="margin:0 0 12px 0;color:#5E7179;font-size:13px">The link works for 1 hour. If the button doesn\'t work, copy this into your browser:<br><span style="word-break:break-all">' . $e($link) . '</span></p>'
      . '<p style="margin:0 0 0 0;color:#5E7179;font-size:13px">Didn\'t ask for this? Ignore this email and your password won\'t change.</p>'
      . '</td></tr><tr><td style="padding:14px 28px;border-top:1px solid #D6E0E3;font:12px Arial,sans-serif;color:#8193A0">&copy; ' . date('Y') . ' Conrad &amp; Claude &middot; hosttally.co.uk</td></tr></table></td></tr></table></body></html>';
    return send_mail($email, "Reset your $app password", $text, $html);
}
function send_welcome(string $email, string $name): void {
    if (!defined('SEND_WELCOME') || !SEND_WELCOME) return;
    $url = app_url();
    $first = trim(explode(' ', trim($name))[0] ?? '');
    $hi = $first !== '' ? "Hi $first," : 'Hi there,';
    $support = defined('SUPPORT_EMAIL') && SUPPORT_EMAIL !== '' ? SUPPORT_EMAIL : MAIL_FROM;
    $app = brand_name();
    $year = date('Y');
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

    $text = "$hi\n\nWelcome to $app! Your account is ready.\n\n"
          . "Open the app: $url\nSign in with: $email\n\n"
          . "GETTING STARTED\n"
          . "1. Choose or add your Airbnb when the app opens.\n"
          . "2. Add your next guest stay (name and dates). Expenses link to it automatically.\n"
          . "3. Tap + Add expense: amount, category, then snap the receipt. No receipt? A dated record is made for you.\n"
          . "4. At tax time, tap the download arrow to export Excel or PDF for your accountant.\n\n"
          . "PUT IT ON YOUR HOME SCREEN\n"
          . "iPhone: open the link in Safari, tap Share, then Add to Home Screen.\n"
          . "Mac: open it in Safari, then File > Add to Dock.\n\n"
          . "Questions, or forgotten your password? Just reply to this email.\n\n"
          . "The HostTally team\nhosttally.co.uk\n\n"
          . "(c) $year Conrad & Claude · hosttally.co.uk";

    $step = function ($n, $t, $d) use ($e) {
        return '<tr><td style="padding:0 0 14px 0;vertical-align:top;width:40px"><div style="width:28px;height:28px;border-radius:14px;background:#1E6670;color:#ffffff;font:bold 14px/28px Arial,sans-serif;text-align:center">' . $n . '</div></td>'
             . '<td style="padding:0 0 14px 0;vertical-align:top;font:15px/1.45 Arial,sans-serif;color:#16262D"><b>' . $e($t) . '</b><br><span style="color:#5E7179">' . $e($d) . '</span></td></tr>';
    };
    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#EEF3F4">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF3F4"><tr><td align="center" style="padding:28px 12px">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #D6E0E3">'
      . '<tr><td style="background:#1E6670;padding:28px 28px 24px 28px">'
      . '<div style="display:inline-block;background:#ffffff;border-radius:18px;padding:5px;margin-bottom:16px"><img src="' . $e($url . 'icon-192.png') . '" width="52" height="52" alt="HostTally" style="display:block;border-radius:13px"></div>'
      . '<div style="font:bold 26px/1.2 Arial,sans-serif;color:#ffffff">Welcome to ' . $e($app) . '!</div>'
      . '<div style="font:15px/1.5 Arial,sans-serif;color:#D7E9EB;margin-top:6px">Your holiday-let expenses, sorted.</div></td></tr>'
      . '<tr><td style="padding:26px 28px 6px 28px;font:15px/1.55 Arial,sans-serif;color:#16262D">'
      . '<p style="margin:0 0 14px 0">' . $e($hi) . '</p>'
      . '<p style="margin:0 0 18px 0">Your account is ready. Here are your details:</p>'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF3F4;border-radius:12px;margin-bottom:22px"><tr><td style="padding:14px 16px;font:14px/1.6 Arial,sans-serif;color:#16262D">'
      . '<span style="color:#5E7179">App address</span><br><a href="' . $e($url) . '" style="color:#1E6670;font-weight:bold;text-decoration:none">' . $e($url) . '</a><br>'
      . '<span style="color:#5E7179">Sign in with</span><br><b>' . $e($email) . '</b></td></tr></table>'
      . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 26px 0"><tr><td style="border-radius:12px;background:#1E6670"><a href="' . $e($url) . '" style="display:inline-block;padding:14px 26px;font:bold 16px Arial,sans-serif;color:#ffffff;text-decoration:none">Open ' . $e($app) . '</a></td></tr></table>'
      . '<div style="font:bold 12px Arial,sans-serif;letter-spacing:1px;color:#5E7179;margin:0 0 12px 0">GETTING STARTED</div>'
      . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%">'
      . $step(1, 'Choose your Airbnb', 'Pick or add the property when the app opens.')
      . $step(2, 'Add your guest stays', 'Type in the name and dates, or import every booking automatically: Settings → Airbnb calendar import, then paste your Airbnb calendar link. Expenses link to the right stay.')
      . $step(3, 'Log expenses as you go', 'Tap + Add expense, pick a category and snap the receipt. No receipt? A dated record is made for you.')
      . $step(4, 'Export at tax time', 'Tap the download arrow for Excel or PDF, receipts included.')
      . '</table>'
      . '<div style="background:#F6F1E6;border-radius:12px;padding:14px 16px;margin:8px 0 22px 0;font:14px/1.55 Arial,sans-serif;color:#16262D"><b>Tip: put it on your home screen</b><br>'
      . 'iPhone: open the link in Safari, tap Share, then <i>Add to Home Screen</i>.<br>Mac: in Safari choose File, then <i>Add to Dock</i>.</div>'
      . '<p style="margin:0 0 6px 0">Questions, or forgotten your password? Just reply to this email.</p>'
      . '<p style="margin:0 0 24px 0">The HostTally team</p></td></tr>'
      . '<tr><td style="padding:16px 28px;border-top:1px solid #D6E0E3;font:12px/1.5 Arial,sans-serif;color:#8193A0">&copy; ' . $year . ' Conrad &amp; Claude &middot; <a href="https://hosttally.co.uk" style="color:#8193A0">hosttally.co.uk</a><br>You received this because an account was created with this email address.</td></tr>'
      . '</table></td></tr></table></body></html>';

    send_mail($email, "Welcome to $app" . ($first !== '' ? ", $first" : ''), $text, $html, $support);

    if (defined('NOTIFY_EMAIL') && NOTIFY_EMAIL !== '') {
        $when = date('j M Y, H:i');
        $who = $name !== '' ? $name : '(not given)';
        $t2 = "New $app sign-up\n\nName: $who\nEmail: $email\nWhen: $when\nApp: $url\n";
        $h2 = '<div style="font:15px/1.6 Arial,sans-serif;color:#16262D"><b>New ' . $e($app) . ' sign-up</b><br>Name: ' . $e($who) . '<br>Email: ' . $e($email) . '<br>When: ' . $e($when) . '<br>App: <a href="' . $e($url) . '">' . $e($url) . '</a></div>';
        send_mail(NOTIFY_EMAIL, "New $app sign-up: $email", $t2, $h2, $email);
    }
}

// ─────────────────────────────────────────────────────────────
$a = (string)($_GET['a'] ?? '');

if ($a === 'health') {
    $ok = in_array('sqlite', PDO::getAvailableDrivers(), true);
    $dir = rtrim(DATA_DIR, '/');
    out(['ok' => $ok && (is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir))), 'php' => PHP_VERSION, 'sqlite' => $ok, 'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'signupConfigured' => SIGNUP_CODE !== 'change-me']);
}

start_session();
auto_backup();
if ($a !== 'receipt' && $a !== 'health') register_shutdown_function(function () {
    if (function_exists('session_write_close')) @session_write_close();
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();   // reply first, then send any emails
    run_reminders();
});

// Receipt images are fetched with a normal GET (used as <img src>).
if ($a === 'receipt') {
    $me = uid();
    $id = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) fail('Not found', 404);
    $s = db()->prepare('SELECT mime FROM receipts WHERE id = ? AND user_id = ?');
    $s->execute([$id, $me]);
    $mime = $s->fetchColumn();
    $file = data_dir() . '/receipts/' . $id;
    if (!$mime || !is_file($file)) fail('Not found', 404);
    session_write_close();
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    if ($mime === 'application/pdf') header('Content-Disposition: inline; filename="bill.pdf"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: private, max-age=31536000, immutable');
    readfile($file);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('Use POST.', 405);
// Requests must come from the app itself (blocks cross-site form posts).
if (($_SERVER['HTTP_X_UPKEEP'] ?? '') !== '1') fail('Request blocked.', 403);

try {
    switch ($a) {
        case 'me': {
            $id = (int)($_SESSION['uid'] ?? 0);
            $u = $id ? user_info($id) : null;
            if ($id && !$u) { $_SESSION = []; }
            if ($u && !empty($u['demo']) && demo_needs_reset($id)) demo_reset($id);
            out(['user' => $u, 'appName' => brand_name(), 'signupOpen' => SIGNUP_CODE !== 'change-me', 'demo' => demo_on()]);
        }
        case 'demo': {
            if (!demo_on()) fail('The demo is switched off.', 403);
            $id = demo_uid();
            if (demo_needs_reset($id)) demo_reset($id);
            session_regenerate_id(true);
            $_SESSION['uid'] = $id;
            out(['user' => user_info($id)]);
        }
        case 'demoreset': {
            $me = uid();
            if (!is_demo_uid($me)) fail('Only the demo account can be reset.', 403);
            demo_reset($me);
            out(['ok' => true]);
        }
        case 'signup': {
            $b = body();
            if (SIGNUP_CODE === 'change-me') fail('Sign-ups are switched off. The site owner needs to set an invite code in config.php.', 403, 'signup_closed');
            if (too_many_attempts()) fail('Too many attempts. Wait 15 minutes and try again.', 429);
            if (!hash_equals(SIGNUP_CODE, (string)($b['code'] ?? ''))) { note_attempt(); fail('That invite code isn\'t right. Check it with the person who invited you.', 400, 'bad_code'); }
            $email = strtolower(trim((string)($b['email'] ?? '')));
            $pass = (string)($b['password'] ?? '');
            $rawName = (string)($b['name'] ?? '');
            $name = trim(function_exists('mb_substr') ? mb_substr($rawName, 0, 80) : substr($rawName, 0, 80));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $email === DEMO_EMAIL) fail('Enter a valid email address.');
            if (strlen($pass) < 8) fail('Use a password of at least 8 characters.');
            $s = db()->prepare('SELECT id FROM users WHERE email = ?');
            $s->execute([$email]);
            if ($s->fetchColumn()) fail('There\'s already an account with that email. Sign in instead.', 409, 'exists');
            db()->prepare('INSERT INTO users (email, name, pass, created) VALUES (?, ?, ?, ?)')->execute([$email, $name, password_hash($pass, PASSWORD_DEFAULT), time()]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)db()->lastInsertId();
            try { send_welcome($email, $name); } catch (Throwable $mailErr) { error_log('HostTally welcome email: ' . $mailErr->getMessage()); }
            out(['user' => user_info((int)$_SESSION['uid'])]);
        }
        case 'login': {
            $b = body();
            if (too_many_attempts()) fail('Too many attempts. Wait 15 minutes and try again.', 429);
            $email = strtolower(trim((string)($b['email'] ?? '')));
            $s = db()->prepare('SELECT id, pass FROM users WHERE email = ?');
            $s->execute([$email]);
            $u = $s->fetch();
            if (!$u || !password_verify((string)($b['password'] ?? ''), $u['pass'])) { note_attempt(); usleep(400000); fail('That email and password don\'t match.', 401, 'bad_login'); }
            if (password_needs_rehash($u['pass'], PASSWORD_DEFAULT)) db()->prepare('UPDATE users SET pass = ? WHERE id = ?')->execute([password_hash((string)$b['password'], PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            out(['user' => user_info((int)$u['id'])]);
        }
        case 'forgot': {
            $b = body();
            if (too_many_attempts()) fail('Too many attempts. Wait 15 minutes and try again.', 429);
            note_attempt();
            $email = strtolower(trim((string)($b['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter the email address you signed up with.');
            if (!defined('MAIL_FROM') || MAIL_FROM === '') fail('Password reset emails aren\'t set up on this site yet. Ask the site owner to set MAIL_FROM in config.php.', 500, 'mail_off');
            $s = db()->prepare('SELECT id, name FROM users WHERE email = ?');
            $s->execute([$email]);
            $u = $s->fetch();
            if ($u && $email !== DEMO_EMAIL) {
                db()->prepare('DELETE FROM resets WHERE user_id = ? OR expires < ?')->execute([$u['id'], time()]);
                $token = bin2hex(random_bytes(24));
                db()->prepare('INSERT INTO resets (token_hash, user_id, expires) VALUES (?, ?, ?)')->execute([hash('sha256', $token), $u['id'], time() + 3600]);
                send_reset($email, (string)$u['name'], app_url() . '?reset=' . $token);
            }
            // Same answer whether or not the email exists, so accounts can't be discovered.
            out(['ok' => true]);
        }
        case 'reset': {
            $b = body();
            if (too_many_attempts()) fail('Too many attempts. Wait 15 minutes and try again.', 429);
            $token = (string)($b['token'] ?? '');
            $pass = (string)($b['password'] ?? '');
            if (strlen($pass) < 8) fail('Use a password of at least 8 characters.');
            $s = db()->prepare('SELECT user_id, expires FROM resets WHERE token_hash = ?');
            $s->execute([hash('sha256', $token)]);
            $r = $s->fetch();
            if (!$r || $r['expires'] < time()) { note_attempt(); fail('This reset link has expired or already been used. Ask for a new one.', 400, 'bad_token'); }
            db()->prepare('UPDATE users SET pass = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $r['user_id']]);
            db()->prepare('DELETE FROM resets WHERE user_id = ?')->execute([$r['user_id']]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$r['user_id'];
            out(['user' => user_info((int)$r['user_id'])]);
        }
        case 'icalsync': {
            $me = uid();
            $b = body();
            if (is_demo_uid($me)) {   // never import real calendars into the shared demo
                db()->prepare('DELETE FROM docs WHERE user_id = ? AND coll = ? AND id = ?')->execute([$me, 'config', 'icals']);
                out(['added' => 0, 'updated' => 0, 'removed' => 0, 'errors' => [], 'skipped' => true, 'demo' => true]);
            }
            session_write_close();
            out(ical_sync($me, !empty($b['force'])));
        }
        case 'icalcheck': {
            if (is_demo_uid(uid())) fail('Calendar import works in your own account, not the shared demo. Create your own account to connect your Airbnb calendar.', 403);
            $b = body();
            $url = trim((string)($b['url'] ?? ''));
            if (!ical_url_ok($url)) fail('That doesn\'t look like an Airbnb calendar link. It should start https://www.airbnb… and contain /calendar/ical/', 400);
            try { $n = count(ical_parse(ical_fetch($url))); } catch (Throwable $e) { fail($e->getMessage(), 400); }
            out(['ok' => true, 'bookings' => $n]);
        }
        case 'logout': {
            $_SESSION = [];
            session_destroy();
            out(['ok' => true]);
        }
        case 'password': {
            $me = uid();
            if (is_demo_uid($me)) fail('The demo account has no password to change.', 403);
            $b = body();
            $s = db()->prepare('SELECT pass FROM users WHERE id = ?');
            $s->execute([$me]);
            if (!password_verify((string)($b['old'] ?? ''), (string)$s->fetchColumn())) fail('Your current password isn\'t right.', 400, 'bad_password');
            $new = (string)($b['new'] ?? '');
            if (strlen($new) < 8) fail('Use a new password of at least 8 characters.');
            db()->prepare('UPDATE users SET pass = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $me]);
            out(['ok' => true]);
        }
        case 'all': {
            $me = uid();
            session_write_close();
            $s = db()->prepare('SELECT coll, id, data FROM docs WHERE user_id = ?');
            $s->execute([$me]);
            $res = ['expenses' => [], 'stays' => [], 'bills' => [], 'config' => []];
            foreach ($s as $r) {
                if (!isset($res[$r['coll']])) continue;
                $res[$r['coll']][] = ['id' => $r['id'], 'data' => json_decode($r['data'], true)];
            }
            out($res);
        }
        case 'set': {
            $me = uid();
            $b = body();
            $coll = (string)($b['coll'] ?? '');
            $id = (string)($b['id'] ?? '');
            if (!in_array($coll, COLLECTIONS, true) || !preg_match(ID_RE, $id)) fail('Bad request.');
            if (!isset($b['data']) || !is_array($b['data'])) fail('Bad request.');
            if ($coll === 'config' && $id === 'icals' && is_demo_uid($me)) fail('Calendar import works in your own account, not the shared demo.', 403);
            $json = json_encode($b['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || strlen($json) > 256000) fail('That entry is too large.');
            $c = db()->prepare('SELECT COUNT(*) FROM docs WHERE user_id = ?');
            $c->execute([$me]);
            if ((int)$c->fetchColumn() > 20000) fail('Your ledger is full. Delete some old entries first.', 400, 'quota_exceeded');
            db()->prepare('INSERT OR REPLACE INTO docs (user_id, coll, id, data, updated) VALUES (?, ?, ?, ?, ?)')->execute([$me, $coll, $id, $json, time()]);
            out(['ok' => true]);
        }
        case 'delete': {
            $me = uid();
            $b = body();
            $coll = (string)($b['coll'] ?? '');
            $id = (string)($b['id'] ?? '');
            if (!in_array($coll, COLLECTIONS, true) || !preg_match(ID_RE, $id)) fail('Bad request.');
            db()->prepare('DELETE FROM docs WHERE user_id = ? AND coll = ? AND id = ?')->execute([$me, $coll, $id]);
            out(['ok' => true]);
        }
        case 'upload': {
            $me = uid();
            if (is_demo_uid($me)) { $c = db()->prepare('SELECT COUNT(*) FROM receipts WHERE user_id = ?'); $c->execute([$me]); if ((int)$c->fetchColumn() >= 40) fail('The demo has reached its photo limit. It resets overnight.', 400); }
            $f = $_FILES['f'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) fail('The file didn\'t upload. Try again.', 400);
            if ($f['size'] > MAX_UPLOAD_MB * 1024 * 1024) fail('That file is too large (the limit is ' . MAX_UPLOAD_MB . ' MB).', 413);
            $head = (string)@file_get_contents($f['tmp_name'], false, null, 0, 5);
            if ($head === '%PDF-') {
                $mime = 'application/pdf';
            } else {
                $info = @getimagesize($f['tmp_name']);
                $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                if (!$info || !in_array($info['mime'], $allowed, true)) fail('That file isn\'t a photo or PDF the ledger can store.', 400);
                $mime = $info['mime'];
            }
            $id = bin2hex(random_bytes(16));
            if (!move_uploaded_file($f['tmp_name'], data_dir() . '/receipts/' . $id)) fail('The file couldn\'t be saved on the server.', 500);
            db()->prepare('INSERT INTO receipts (id, user_id, mime, created) VALUES (?, ?, ?, ?)')->execute([$id, $me, $mime, time()]);
            out(['id' => $id]);
        }
        default:
            fail('Unknown action.', 404);
    }
} catch (Throwable $e) {
    error_log('HostTally: ' . $e->getMessage());
    fail('Something went wrong on the server. Try again in a moment.', 500, 'server');
}
