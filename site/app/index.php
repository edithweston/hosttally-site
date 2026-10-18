<?php
// © 2026 Conrad & Claude · HostFactor (hostfactor.co.uk). HostTally is proprietary software. All rights reserved. Copying, modifying, redistributing or reusing this code without written permission is not permitted.
// HostTally front page.
// Served through PHP so hosting caches (CDN / StackCache / LiteSpeed) always
// give visitors the latest version of the app straight after an update.
$f = __DIR__ . '/app.html';
if (!is_file($f)) { http_response_code(500); exit('app.html is missing from this folder.'); }
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-App-Version: ' . md5_file($f));
readfile($f);
