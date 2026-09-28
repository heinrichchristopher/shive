<?php
// harness: php /tmp/api.php GET|POST 'op=...&k=v' [csrf]
[$_, $method, $qs] = $argv; $useTok = ($argv[3] ?? '') === 'nocsrf' ? false : true;
$_SERVER['REQUEST_METHOD'] = $method; parse_str($qs, $p);
if ($method === 'POST') { $_POST = $p; if ($useTok) $_POST['csrf_token'] = 'TOKEN123'; } else $_GET = $p;
require '/usr/local/emhttp/plugins/shive/include/api.php';
