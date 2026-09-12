<?php
/* shive - shared head include for every Shive tab page.
   Each ShiveXxx.page requires this once near the top of its body:
     assets (css+js), the embedded CSRF token, and the container-resume
   safety net (cheap, idempotent - safe to fire on every page view). */
require_once __DIR__ . '/config.php';
exec('/usr/local/emhttp/plugins/shive/scripts/shive-recover --quiet >/dev/null 2>&1 &');
$shiveCssV = @filemtime(__DIR__ . '/../css/shive.css') ?: time();
$shiveJsV  = @filemtime(__DIR__ . '/../js/shive.js') ?: time();
echo "<link rel='stylesheet' href='/plugins/shive/css/shive.css?v=$shiveCssV'>\n";
echo '<script>window.SHIVE_CSRF = ' . json_encode(shive_csrf_token()) . ";</script>\n";
echo "<script src='/plugins/shive/js/shive.js?v=$shiveJsV'></script>\n";
