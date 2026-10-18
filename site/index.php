<?php
// © 2026 Conrad & Claude · HostFactor (hostfactor.co.uk). HostTally is proprietary software. All rights reserved. Copying, modifying, redistributing or reusing this code without written permission is not permitted.
// HostTally home page for hosttally.co.uk.
// Served through PHP so hosting caches never show an old copy.
$file = __DIR__ . '/home.html';
if (!is_file($file)) { http_response_code(404); exit('Page not found'); }
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
readfile($file);
