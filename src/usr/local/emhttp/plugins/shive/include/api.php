<?php
/* shive – AJAX endpoint for the WebGUI and dashboard tile. All responses are JSON.
   GET  ?op=...            read-only operations
   POST op=... csrf_token= state-changing operations (Unraid csrf token required) */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/zfs.php';
require_once __DIR__ . '/docker.php';
require_once __DIR__ . '/retention.php';

header('Content-Type: application/json; charset=utf-8');
$ident = @parse_ini_file('/boot/config/ident.cfg') ?: [];
date_default_timezone_set($ident['timeZone'] ?? 'UTC');

function reply($v, int $code = 200): never { http_response_code($code); echo json_encode($v, JSON_UNESCAPED_SLASHES); exit; }
function fail(string $msg, int $code = 400): never { reply(['ok' => false, 'error' => $msg], $code); }
function script(string $name, array $args, ?int &$rc = null): string {
  return shive_run(SHIVE_SCRIPTS . '/' . $name . ' ' . implode(' ', array_map('escapeshellarg', $args)), $rc);
}

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$in = $post ? $_POST : $_GET;
if ($post) {
  $expected = shive_csrf_token();
  $got = (string)($in['csrf_token'] ?? ($_SERVER['HTTP_X_SHIVE_CSRF'] ?? ''));
  if ($expected === '' || $got !== $expected)
    fail(sprintf('invalid csrf token (received %d chars via %s, var.ini has %d chars%s; POST fields: %s)',
      strlen($got), isset($in['csrf_token']) ? 'form' : (isset($_SERVER['HTTP_X_SHIVE_CSRF']) ? 'header' : 'none'),
      strlen($expected), $expected === '' ? ' - var.ini unreadable' : '', implode(',', array_keys($_POST)) ?: '(empty)'), 403);
}
$op = $in['op'] ?? '';
$json = fn(string $k) => json_decode($in[$k] ?? 'null', true);

switch ($op) {
  /* ---- status / config ---- */
  case 'status':   reply(shive_status_rebuild());
  case 'config':   reply(['ok' => true, 'config' => shive_cfg()]);
  case 'config_save': if (!$post) fail('POST'); shive_cfg_save($json('config') ?: []); reply(['ok' => true, 'config' => shive_cfg()]);

  /* ---- inventory ---- */
  case 'datasets': reply(['ok' => true, 'pools' => array_values(zfs_pools()), 'datasets' => array_values(zfs_datasets())]);
  case 'containers': reply(['ok' => true] + docker_discover(!empty($in['refresh'])));
  case 'mappings': reply(['ok' => true, 'mappings' => docker_mappings()]);
  case 'mappings_save': if (!$post) fail('POST'); docker_mappings_save($json('mappings') ?: []); docker_discover(true); reply(['ok' => true]);

  /* ---- schedules ---- */
  case 'schedules':
    $sch = array_values(shive_schedules());
    $active = @file_get_contents('/etc/cron.d/root') ?: '';
    $need = (bool)array_filter($sch, fn($s) => $s['enabled']);
    reply(['ok' => true, 'schedules' => $sch,
           'cron_active' => !$need || str_contains($active, 'shive-run'),
           'cron_lines' => substr_count($active, 'shive-run')]);
  case 'schedule_save': if (!$post) fail('POST'); $r = shive_schedule_save($json('schedule') ?: []); reply($r, $r['ok'] ? 200 : 400);
  case 'schedule_delete': if (!$post) fail('POST'); reply(['ok' => shive_schedule_delete($in['id'] ?? '')]);
  case 'linked':
    // Accepts either a saved schedule's id, or datasets+recursive straight from the editor
    // (which has no id yet) - so the container-matching rule lives in docker_linked() only,
    // instead of being reimplemented in JS for the unsaved case.
    if (isset($in['datasets'])) {
      $ds = array_values(array_filter((array)$json('datasets')));
      if (!$ds) fail('datasets required');
      reply(['ok' => true, 'containers' => docker_linked($ds, !empty($in['recursive']), true)]);
    }
    $s = shive_schedule_load($in['id'] ?? '') ?: fail('not found', 404);
    reply(['ok' => true, 'containers' => docker_linked($s['datasets'], $s['recursive'], true)]);
  case 'run':
    if (!$post) fail('POST');
    $s = shive_schedule_load($in['id'] ?? '') ?: fail('not found', 404);
    $flags = !empty($in['dry_run']) ? ' --dry-run' : '';
    shive_run('nohup ' . SHIVE_SCRIPTS . '/shive-run ' . $s['id'] . " --force$flags >/dev/null 2>&1 &");   // id: same state-key namespace as cron runs
    reply(['ok' => true, 'dry_run' => $flags !== '']);
  case 'prune_preview':
    $s = shive_schedule_load($in['id'] ?? '') ?: fail('not found', 404);
    $res = [];
    foreach ($s['datasets'] as $ds) {
      $res["source:$ds"] = json_decode(script('shive-prune', ['--sched', $s['id'], '--location', 'source', '--dataset', $ds, '--json']), true);
      if ($s['local_target']['enabled']) {
        $t = shive_target_for($s['local_target']['dataset'], $ds);
        $res["local:$t"] = json_decode(script('shive-prune', ['--sched', $s['id'], '--location', 'local', '--dataset', $t, '--json']), true);
      }
      if ($s['remote_target']['enabled']) {
        $t = shive_target_for($s['remote_target']['dataset'], $ds);
        $res["remote:$t"] = json_decode(script('shive-prune', ['--sched', $s['id'], '--location', 'remote', '--dataset', $t, '--target', $s['remote_target']['spec'], '--json']), true);
      }
    }
    reply(['ok' => true, 'preview' => $res]);
  case 'target_parent_status':
    // local_target/remote_target.dataset is a ROOT: every source lands at <root>/<basename>,
    // so it's the root itself (not some computed parent) that must already exist for the
    // first receive to succeed - zfs receive creates exactly one new leaf, never a chain.
    $ds = trim($in['dataset'] ?? ''); $target = $in['target'] ?? 'local';
    if ($ds === '') fail('dataset required');
    zfs_exec($target, 'zfs list -H -o name ' . escapeshellarg($ds), $rc);
    reply(['ok' => true, 'root' => $ds, 'exists' => $rc === 0]);
  case 'target_parent_create':
    if (!$post) fail('POST');
    $ds = trim($in['dataset'] ?? ''); $target = $in['target'] ?? 'local';
    if ($ds === '') fail('dataset required');
    $o = zfs_exec($target, 'zfs create -p ' . escapeshellarg($ds), $rc);
    reply(['ok' => $rc === 0, 'output' => $o, 'root' => $ds], $rc === 0 ? 200 : 500);
  case 'test_remote':
    $spec = $in['spec'] ?? '';
    try { [$pre, $ds] = zfs_ssh($spec); } catch (Throwable $e) { fail($e->getMessage()); }
    // $ds is the configured backup ROOT (sources land at <root>/<basename>), so probe it
    // directly - taking its dirname here was left over from when the field was a literal path.
    $o = shive_run($pre . ' ' . escapeshellarg('zfs list -H -o name,avail ' . escapeshellarg($ds) . ' && zfs --version | head -1'), $rc);
    reply(['ok' => $rc === 0, 'output' => $o]);

  /* ---- snapshots / browse / restore ---- */
  case 'snapshots':
    $target = $in['target'] ?? 'local'; $ds = $in['dataset'] ?? '';
    if ($ds === '') fail('dataset required');
    $prefix = !empty($in['all']) ? '' : 'shive-';
    reply(['ok' => true, 'snapshots' => zfs_snapshots($ds, $target, !empty($in['recursive']), $prefix)]);
  case 'snapshot_flag':
    if (!$post) fail('POST');
    $args = [empty($in['unset']) ? 'flag' : 'unflag', '--snapshot', $in['snapshot'] ?? '', '--target', $in['target'] ?? 'local'];
    if (!empty($in['recursive'])) $args[] = '--recursive';
    $o = script('shive-snapshot', $args, $rc);
    reply(['ok' => $rc === 0, 'output' => $o], $rc === 0 ? 200 : 500);
  case 'snapshot_delete':
    if (!$post) fail('POST');
    if (empty($in['confirm'])) fail('confirmation required');
    $args = ['destroy', '--snapshot', $in['snapshot'] ?? '', '--target', $in['target'] ?? 'local', '--yes'];
    if (!empty($in['recursive'])) $args[] = '--recursive';
    if (!empty($in['dry_run'])) $args[] = '--dry-run';
    $o = script('shive-snapshot', $args, $rc);
    reply(['ok' => $rc === 0, 'output' => $o], $rc === 0 ? 200 : 500);
  case 'stage':
    if (!$post) fail('POST');
    $o = script('shive-restore', ['stage', '--snapshot', $in['snapshot'] ?? '', '--target', $in['target'] ?? 'local'], $rc);
    $j = null; foreach (array_reverse(explode("\n", trim($o))) as $line) if (str_starts_with($line, '{')) { $j = json_decode($line, true); break; }
    $rc === 0 && $j ? reply(['ok' => true] + $j) : fail($o, 500);
  case 'unstage':
    if (!$post) fail('POST');
    script('shive-restore', ['unstage', '--id', $in['id'] ?? '']); reply(['ok' => true]);
  case 'browse':
    $p = $in['path'] ?? ''; $t = $in['target'] ?? 'local';
    if (!preg_match('#^(/mnt/[^/]+(?:/[^/]+)*/\.zfs/snapshot/[^/]+|/mnt/shive/restore/[^/]+|/tmp/shive-restore/[^/]+)(/.*)?$#', $p)) fail('path outside snapshot staging area');
    $entries = zfs_browse($p, $t);
    // Inside <mountpoint>/.zfs/snapshot/<snap>/<rel>, a child dataset shows up as an EMPTY directory
    // (its data lives in its own snapshot). Flag those so the GUI points at the child's snapshot.
    if ($t === 'local' && preg_match('#^(/mnt/.+?)/\.zfs/snapshot/[^/]+(/.*)?$#', $p, $mm)) {
      $rel = trim($mm[2] ?? '', '/');
      $mps = []; foreach (zfs_datasets() as $d) $mps[$d['mountpoint']] = $d['name'];
      foreach ($entries as &$e) if ($e['dir']) {
        $live = $mm[1] . ($rel !== '' ? "/$rel" : '') . '/' . $e['name'];
        if (isset($mps[$live]) && $mps[$live] !== ($mps[$mm[1]] ?? '')) $e['child_dataset'] = $mps[$live];
      } unset($e);
    }
    reply(['ok' => true, 'entries' => $entries]);
  case 'restore_file':
    if (!$post) fail('POST');
    $args = ['file', '--snapshot', $in['snapshot'] ?? '', '--target', $in['target'] ?? 'local', '--path', $in['path'] ?? '',
             '--dest', $in['dest'] ?? '', '--mode', ($in['mode'] ?? 'copy') === 'overwrite' ? 'overwrite' : 'copy'];
    if (!empty($in['confirm'])) $args[] = '--yes';
    if (!empty($in['dry_run'])) $args[] = '--dry-run';
    $o = script('shive-restore', $args, $rc); reply(['ok' => $rc === 0, 'output' => $o]);
  case 'restore_dataset':
    if (!$post) fail('POST');
    if (empty($in['confirm'])) fail('confirmation required');
    $args = ['dataset', '--snapshot', $in['snapshot'] ?? '', '--method', ($in['method'] ?? 'rsync') === 'rollback' ? 'rollback' : 'rsync', '--yes'];
    if (!empty($in['dry_run'])) $args[] = '--dry-run';
    $o = script('shive-restore', $args, $rc); reply(['ok' => $rc === 0, 'output' => $o]);
  case 'restore_dr':
    if (!$post) fail('POST');
    if (empty($in['confirm'])) fail('confirmation required');
    $args = ['dr', '--target', $in['target'] ?? '', '--snapshot', $in['snapshot'] ?? '', '--to', $in['to'] ?? ''];
    if (!empty($in['recursive'])) $args[] = '--recursive';
    if (!empty($in['dry_run'])) $args[] = '--dry-run';
    $o = script('shive-restore', $args, $rc); reply(['ok' => $rc === 0, 'output' => $o]);

  /* ---- logs ---- */
  case 'logs':
    $dir = rtrim(shive_cfg()['LOG_DIR'], '/'); $list = [];
    $names = []; foreach (shive_schedules() as $s) $names[$s['id']] = $s['name'];
    foreach (glob("$dir/*/*.log") ?: [] as $f) {
      $key = basename(dirname($f));
      $list[] = ['schedule' => $key, 'label' => $names[$key] ?? "$key (deleted)", 'file' => basename($f), 'size' => filesize($f), 'mtime' => filemtime($f)];
    }
    if (is_file("$dir/restore.log")) $list[] = ['schedule' => 'restore', 'file' => 'restore.log', 'size' => filesize("$dir/restore.log"), 'mtime' => filemtime("$dir/restore.log")];
    usort($list, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    reply(['ok' => true, 'logs' => array_slice($list, 0, 200)]);
  case 'log':
    $dir = rtrim(shive_cfg()['LOG_DIR'], '/');
    $f = basename($in['file'] ?? ''); $s = basename($in['schedule'] ?? '');
    $path = $s === 'restore' ? "$dir/restore.log" : "$dir/$s/$f";
    if (!is_file($path)) fail('not found', 404);
    reply(['ok' => true, 'content' => shive_run('tail -n 2000 ' . escapeshellarg($path))]);
  case 'history':
    $out = [];
    foreach (shive_schedules() as $s) $out[$s['id']] = ['name' => $s['name'], 'last' => shive_last_status($s['id']), 'running' => shive_running($s['id']),
      'sends' => array_filter(['local' => shive_last_status($s['id'] . '.send-local'), 'remote' => shive_last_status($s['id'] . '.send-remote')])];
    reply(['ok' => true, 'history' => $out]);

  default: fail("unknown op '$op'", 404);
}
