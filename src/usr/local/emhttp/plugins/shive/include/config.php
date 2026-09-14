<?php
/* shive – configuration, schedules, cron generation, status aggregation. */
declare(strict_types=1);

const SHIVE_PLG     = '/boot/config/plugins/shive';
const SHIVE_VAR     = '/var/local/shive';
const SHIVE_EMHTTP  = '/usr/local/emhttp/plugins/shive';
const SHIVE_SCRIPTS = SHIVE_EMHTTP . '/scripts';
const SHIVE_CFG_DEFAULTS = [
  'LOG_DIR' => '/var/log/shive', 'NOTIFY_ON_SUCCESS' => 'yes', 'DOCKER_STOP_TIMEOUT' => '60',
  'SSH_KEY' => '', 'SSH_OPTS' => '-o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new',
  'CATCHUP_ON_START' => 'yes', 'RESTORE_CLONE_TTL' => '7200', 'PRERESTORE_KEEP' => '3',
];

/* Unraid's session CSRF token from var.ini. RAW scanner: PHP 8.3's default INI scanner
   rejects some var.ini values and then returns false for the whole file. */
function shive_csrf_token(): string {
  $v = @parse_ini_file('/var/local/emhttp/var.ini', false, INI_SCANNER_RAW) ?: [];
  return trim((string)($v['csrf_token'] ?? ''), "\"' ");
}
function shive_cfg(): array {
  $c = @parse_ini_file(SHIVE_PLG . '/shive.cfg') ?: [];
  return array_merge(SHIVE_CFG_DEFAULTS, $c);
}
function shive_cfg_save(array $c): void {
  $c = array_intersect_key(array_merge(shive_cfg(), $c), SHIVE_CFG_DEFAULTS);
  $out = '';
  foreach ($c as $k => $v) $out .= $k . '="' . str_replace('"', '', (string)$v) . '"' . "\n";
  shive_atomic_write(SHIVE_PLG . '/shive.cfg', $out);
}
function shive_atomic_write(string $file, string $data): void {
  @mkdir(dirname($file), 0755, true);
  $tmp = $file . '.tmp';
  file_put_contents($tmp, $data);
  rename($tmp, $file);
}
function shive_run(string $cmd, ?int &$rc = null): string {
  exec($cmd . ' 2>&1', $out, $rc);
  return implode("\n", $out);
}

/* ---------------- schedules ---------------- */
function shive_retention_defaults(): array {
  return ['mode' => 'gfs', 'days' => 30, 'hourly' => 0, 'daily' => 7, 'weekly' => 4, 'monthly' => 3];
}
function shive_schedule_defaults(): array {
  return [
    'name' => '', 'id' => 0, 'created' => 0, 'enabled' => true, 'datasets' => [], 'recursive' => true,
    // child datasets to leave out of the recursive snapshot entirely (no snapshot -> nothing to send)
    'exclude_datasets' => [],
    'frequency' => 'daily', 'time' => '03:00', 'weekday' => 0, 'monthday' => 1, 'cron' => '',
    'docker_aware' => false,
    // exclude_datasets on a target is ADDITIVE to the schedule-level list: a dataset that never
    // gets a snapshot can't be replicated anyway, so a target can only ever exclude more, not less.
    'local_target'  => ['enabled' => false, 'dataset' => '', 'exclude_datasets' => [], 'own_schedule' => false,
                        'frequency' => 'daily', 'time' => '04:00', 'weekday' => 0, 'monthday' => 1, 'cron' => ''],
    'remote_target' => ['enabled' => false, 'host' => '', 'port' => 22, 'user' => 'root', 'dataset' => '',
                        'exclude_datasets' => [],
                        'own_schedule' => false, 'frequency' => 'weekly', 'time' => '04:00', 'weekday' => 0, 'monthday' => 1, 'cron' => ''],
    'retention' => ['source' => shive_retention_defaults(), 'local' => shive_retention_defaults(), 'remote' => shive_retention_defaults()],
    'exclude_props' => ['mountpoint', 'canmount', 'sharenfs', 'sharesmb'],
    'notify_success' => true,
  ];
}
/* Destination dataset for one source under a configured backup root. Mirrors target_for() in
   scripts/shive-run - keep the two in sync; they are the same rule on either side of the
   PHP/shell split, and a divergence silently makes the GUI report a different dataset than the
   one the job actually touches (which is exactly what happened to prune_preview once). */
function shive_target_for(string $root, string $sourceDataset): string {
  return rtrim($root, '/') . '/' . basename($sourceDataset);
}
function shive_schedule_file(string $id): string { return SHIVE_PLG . '/schedules/' . $id . '.json'; }
/* Snapshot tag prefix for a schedule: shive-<hex>-  (full name: shive-<hex>-DDMMYYYY-HHMM).
   IDs are random 6-hex-digit strings, checked against every id ever handed out (not just
   currently-active ones), so retention can never touch another (even deleted) schedule's
   snapshots and a re-generated id can never collide with one that still has snapshots. */
function shive_tag_prefix(array $s): string { return 'shive-' . $s['id'] . '-'; }
/* Fields shive_schedule_load() computes on the fly. They must never be written back, or a stale
   copy would survive a config change (e.g. an old cron_expr after the frequency was edited).
   One definition, used by every path that persists a schedule. */
function shive_strip_derived(array $s): array {
  unset($s['tag_prefix'], $s['cron_expr'], $s['spec'],
        $s['local_target']['cron_expr'], $s['remote_target']['cron_expr'], $s['remote_target']['spec'],
        $s['local_target']['exclude_effective'], $s['remote_target']['exclude_effective']);
  return $s;
}
function shive_valid_id(string $id): bool { return (bool)preg_match('/^[0-9a-f]{6}$/', $id); }
function shive_new_id(): string {
  $used = array_flip(array_filter(array_map('trim', @file(SHIVE_PLG . '/used_ids') ?: [])));
  foreach (shive_schedules_raw() as $s) if (!empty($s['id'])) $used[$s['id']] = true;   // defense in depth
  do { $id = sprintf('%06x', random_int(0, 0xFFFFFF)); } while (isset($used[$id]));
  $fh = fopen(SHIVE_PLG . '/used_ids', 'c+'); flock($fh, LOCK_EX);
  fseek($fh, 0, SEEK_END); fwrite($fh, $id . "\n"); fflush($fh); flock($fh, LOCK_UN); fclose($fh);
  return $id;
}
/* Raw schedule JSONs (no id-migration, no derived fields) - used only by shive_new_id()
   so id assignment never recurses into shive_schedules() -> shive_schedule_load() -> shive_new_id(). */
function shive_schedules_raw(): array {
  $out = [];
  foreach (glob(SHIVE_PLG . '/schedules/*.json') ?: [] as $f) {
    $j = json_decode((string)file_get_contents($f), true);
    if (is_array($j)) $out[] = $j;
  }
  return $out;
}
/* Display name. Since schedules are stored as <id>.json and cron/state/logs are all keyed
   by the id, the name is pure payload inside the JSON - it is never a path component, never
   reaches a shell, and needs no uniqueness. So: anything printable, up to 64 characters.
   Slashes, umlauts, "&", quotes and so on are all fine; output is escaped at display time. */
function shive_valid_name(string $n): bool {
  $len = function_exists('mb_strlen') ? mb_strlen($n, 'UTF-8') : strlen($n);   // mbstring is optional
  if (trim($n) === '' || $len > 64) return false;
  if (!preg_match('//u', $n)) return false;                    // must be valid UTF-8
  return !preg_match('/[\x00-\x1F\x7F]/u', $n);              // no control characters
}

function shive_schedule_load(string $id): ?array {
  if (!shive_valid_id($id) || !is_file(shive_schedule_file($id))) return null;
  $s = json_decode((string)file_get_contents(shive_schedule_file($id)), true);
  if (!is_array($s)) return null;
  $raw = $s;
  $s = array_replace_recursive(shive_schedule_defaults(), $s);
  // array_replace_recursive merges lists by index (a 1-element exclude_props would keep the
  // other three defaults) - take list fields verbatim from the file
  foreach (['datasets', 'exclude_props', 'exclude_datasets'] as $k) if (isset($raw[$k]) && is_array($raw[$k])) $s[$k] = array_values($raw[$k]);
  foreach (['local_target', 'remote_target'] as $loc)
    if (isset($raw[$loc]['exclude_datasets']) && is_array($raw[$loc]['exclude_datasets']))
      $s[$loc]['exclude_datasets'] = array_values($raw[$loc]['exclude_datasets']);
  $s['id'] = $id;
  if (empty($raw['created'])) {   // pre-existing schedule from before this field: best-effort backfill
    $s['created'] = @filemtime(shive_schedule_file($id)) ?: time();
    shive_atomic_write(shive_schedule_file($id), json_encode(shive_strip_derived($s), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  }
  $s['tag_prefix'] = shive_tag_prefix($s);
  $r = $s['remote_target'];
  $s['remote_target']['spec'] = $r['host'] !== '' ? sprintf('ssh://%s@%s:%d/%s', $r['user'] ?: 'root', $r['host'], (int)($r['port'] ?: 22), $r['dataset']) : '';
  $s['cron_expr'] = shive_cron_expr($s);
  foreach (['local_target', 'remote_target'] as $loc) {
    $s[$loc]['cron_expr'] = $s[$loc]['own_schedule'] ? shive_cron_expr($s[$loc]) : '';
    // effective = snapshot exclusions + this target's own (additive). Derived here so the shell
    // side never has to merge the two lists itself and can't drift from this definition.
    $s[$loc]['exclude_effective'] = array_values(array_unique(array_merge(
      $s['exclude_datasets'], $s[$loc]['exclude_datasets'] ?? [])));
  }
  return $s;
}
/* One-time migration: schedules used to be stored as <name>.json (and before that without an
   id at all). Rewrite any such file to <id>.json, assigning an id if it lacks a valid one. */
function shive_migrate_schedule_files(): void {
  foreach (glob(SHIVE_PLG . '/schedules/*.json') ?: [] as $f) {
    $stem = basename($f, '.json');
    if (shive_valid_id($stem)) continue;
    $s = json_decode((string)file_get_contents($f), true);
    if (!is_array($s)) continue;
    if (!shive_valid_id((string)($s['id'] ?? ''))) $s['id'] = shive_new_id();
    if (($s['name'] ?? '') === '') $s['name'] = $stem;          // old scheme: filename was the name
    shive_atomic_write(shive_schedule_file($s['id']), json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    @unlink($f);
  }
}
function shive_schedules(): array {
  shive_migrate_schedule_files();
  $out = [];
  foreach (glob(SHIVE_PLG . '/schedules/*.json') ?: [] as $f) {
    $s = shive_schedule_load(basename($f, '.json'));
    if ($s) $out[$s['id']] = $s;
  }
  uasort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
  return $out;
}
/* Resolves a schedule id (cron, scripts, GUI) or, as a convenience for the CLI, a display name. */
function shive_schedule_resolve(string $ref): ?array {
  if (shive_valid_id($ref)) return shive_schedule_load($ref);
  foreach (shive_schedules() as $s) if (strcasecmp($s['name'], $ref) === 0) return $s;
  return null;
}
function shive_schedule_validate(array $s): array {
  $e = [];
  $isDataset = fn($d) => (bool)preg_match('#^[a-zA-Z0-9][\w.\-]*(/[\w.\-]+)*$#', (string)$d);
  if (!shive_valid_name($s['name'] ?? '')) $e[] = 'name: 1-64 characters, no control characters';
  if (empty($s['datasets'])) $e[] = 'at least one dataset required';
  foreach ($s['datasets'] as $d) if (!$isDataset($d)) $e[] = "invalid dataset '$d'";
  // Exclusions must be shaped like datasets AND actually sit below one of the sources - otherwise a
  // typo silently excludes nothing, which is the failure mode you'd never notice until a restore.
  $under = function (string $x) use ($s) {
    foreach ($s['datasets'] as $src) if ($x === $src || str_starts_with($x, $src . '/')) return true;
    return false;
  };
  $checkExcl = function (array $list, string $where) use (&$e, $isDataset, $under, $s) {
    // Existence is only checked when ZFS is actually answering and this schedule's own sources are
    // visible - otherwise a stopped array or an exported pool would block saving a valid schedule.
    // zfs.php isn't a dependency of config.php, so this degrades to "no check" if it isn't loaded.
    static $known = null;
    if ($known === null) $known = function_exists('zfs_datasets') ? array_keys(zfs_datasets()) : [];
    $sourcesVisible = $known && !array_diff($s['datasets'], $known);
    foreach ($list as $x) {
      if (!$isDataset($x)) { $e[] = "$where: invalid dataset '$x'"; continue; }
      if (!$under($x)) { $e[] = "$where: '$x' is not below any of this schedule's source datasets"; continue; }
      if (in_array($x, $s['datasets'], true)) { $e[] = "$where: '$x' is a source dataset itself - remove it from the sources instead"; continue; }
      if ($sourcesVisible && !in_array($x, $known, true))
        $e[] = "$where: '$x' does not exist - check the spelling, an exclusion that matches nothing silently excludes nothing";
    }
  };
  $checkExcl($s['exclude_datasets'], 'snapshot exclusions');
  if (!empty($s['exclude_datasets']) && empty($s['recursive']))
    $e[] = 'snapshot exclusions only apply to a recursive schedule';
  $checkExcl($s['local_target']['exclude_datasets'] ?? [], 'local target exclusions');
  $checkExcl($s['remote_target']['exclude_datasets'] ?? [], 'remote target exclusions');
  if (count($s['datasets']) > 1) {
    $b = array_map(fn($d) => basename($d), $s['datasets']);
    if (count($b) !== count(array_unique($b))) $e[] = 'the last path component of each source dataset must be unique - every source is stored as <target-root>/<basename>, so identical basenames would collide in the target';
  }
  if ($s['frequency'] === 'custom' && !preg_match('/^(\S+\s+){4}\S+$/', trim($s['cron'] ?? ''))) $e[] = 'custom cron needs 5 fields';
  foreach (['local_target' => 'local', 'remote_target' => 'remote'] as $k => $label)
    if (!empty($s[$k]['enabled']) && !empty($s[$k]['own_schedule']) && $s[$k]['frequency'] === 'custom'
        && !preg_match('/^(\S+\s+){4}\S+$/', trim($s[$k]['cron'] ?? ''))) $e[] = "$label send: custom cron needs 5 fields";
  if (!empty($s['local_target']['enabled'])) {
    $t = $s['local_target']['dataset'];
    if ($t === '') $e[] = 'local target dataset required';
    elseif (!$isDataset($t)) $e[] = "invalid local backup root '$t'";
    foreach ($s['datasets'] as $d) if ($t === $d || str_starts_with($t, $d . '/')) $e[] = "local target '$t' lies inside source '$d'";
  }
  if (!empty($s['remote_target']['enabled'])) {
    $r = $s['remote_target'];
    if ($r['host'] === '' || $r['dataset'] === '') $e[] = 'remote host and dataset required';
    // these end up in an ssh command line; keep them to the shapes ssh/zfs actually accept
    if ($r['dataset'] !== '' && !$isDataset($r['dataset'])) $e[] = "invalid remote backup root '{$r['dataset']}'";
    if ($r['host'] !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $r['host'])) $e[] = 'remote host: letters, digits, dot, dash, underscore only';
    if (($r['user'] ?? '') !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $r['user'])) $e[] = 'remote user: letters, digits, dot, dash, underscore only';
    if ((int)$r['port'] < 1 || (int)$r['port'] > 65535) $e[] = 'remote port must be 1-65535';
  }
  foreach (['source', 'local', 'remote'] as $loc) {
    $r = $s['retention'][$loc];
    if (!in_array($r['mode'], ['age', 'gfs'], true)) $e[] = "retention $loc: mode must be age|gfs";
    foreach (['days', 'hourly', 'daily', 'weekly', 'monthly'] as $k) if ((int)($r[$k] ?? 0) < 0) $e[] = "retention $loc: $k must be >= 0";
  }
  return $e;
}
function shive_schedule_save(array $in): array {
  $s = array_replace_recursive(shive_schedule_defaults(), $in);
  foreach (['datasets', 'exclude_props', 'exclude_datasets'] as $k) if (isset($in[$k]) && is_array($in[$k])) $s[$k] = array_values($in[$k]);   // see load()
  foreach (['local_target', 'remote_target'] as $loc)
    if (isset($in[$loc]['exclude_datasets']) && is_array($in[$loc]['exclude_datasets']))
      $s[$loc]['exclude_datasets'] = array_values($in[$loc]['exclude_datasets']);
  $s['name'] = trim((string)$s['name']);   // free text; only trimmed, never rewritten
  $s['datasets'] = array_values(array_unique(array_filter(array_map('trim', (array)$s['datasets']))));
  foreach (['enabled', 'recursive', 'docker_aware', 'notify_success'] as $b) $s[$b] = (bool)$s[$b];
  foreach (['local_target', 'remote_target'] as $loc) {
    $s[$loc]['enabled'] = (bool)$s[$loc]['enabled'];
    $s[$loc]['own_schedule'] = (bool)$s[$loc]['own_schedule'];
  }
  $s['remote_target']['port'] = (int)$s['remote_target']['port'];
  foreach (['source', 'local', 'remote'] as $loc) foreach (['days', 'hourly', 'daily', 'weekly', 'monthly'] as $k) $s['retention'][$loc][$k] = (int)($s['retention'][$loc][$k] ?? 0);
  $s['exclude_props'] = array_values(array_filter(array_map('trim', (array)$s['exclude_props'])));
  $s['exclude_datasets'] = array_values(array_filter(array_map('trim', (array)$s['exclude_datasets'])));
  foreach (['local_target', 'remote_target'] as $loc)
    $s[$loc]['exclude_datasets'] = array_values(array_filter(array_map('trim', (array)($s[$loc]['exclude_datasets'] ?? []))));
  $s = shive_strip_derived($s);
  $errors = shive_schedule_validate($s);
  if ($errors) return ['ok' => false, 'errors' => $errors];
  // ID is immutable once assigned. Only an id that belongs to an EXISTING schedule is accepted
  // from the caller - anything else gets a fresh one, so an id can never enter the system without
  // going through shive_new_id() and the used_ids never-reuse registry.
  $reqId = (string)($in['id'] ?? '');
  $isExisting = shive_valid_id($reqId) && is_file(shive_schedule_file($reqId));
  $s['id'] = $isExisting ? $reqId : shive_new_id();
  // created is immutable too, needed so a brand-new schedule that has never run isn't mistaken
  // for one that's "infinitely overdue" the moment the array starts (see cli.php catchup).
  $s['created'] = $isExisting ? (int)(shive_schedule_load($s['id'])['created'] ?? time()) : time();
  shive_atomic_write(shive_schedule_file($s['id']), json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  shive_cron_write();
  return ['ok' => true, 'schedule' => shive_schedule_load($s['id'])];
}
function shive_schedule_delete(string $id): bool {
  if (!shive_valid_id($id)) return false;
  @unlink(shive_schedule_file($id));
  foreach (['', '.send-local', '.send-remote'] as $suf) @unlink(SHIVE_PLG . "/state/$id$suf.last.json");
  shive_cron_write();
  return true;
}

/* Every place a given snapshot exists: the source dataset plus the configured local/remote
   counterparts. Used to keep the shive:important flag consistent across all copies - relying on
   zfs send to carry the property does not work, because a flag set AFTER a snapshot was already
   replicated is never re-sent, and a non-recursive send omits properties entirely.
   $ds is whichever copy the user clicked on (source or a target); the schedule is identified by
   the id embedded in the snapshot name. Returns [['target' => 'local'|<ssh spec>, 'dataset' => …]]. */
function shive_snapshot_locations(string $ds, string $snapName): array {
  if (!preg_match('/^shive-([0-9a-f]{6})-/', $snapName, $m)) return [];
  $s = shive_schedule_load($m[1]);
  if (!$s) return [];
  foreach ($s['datasets'] as $src) {
    $group = [['target' => 'local', 'dataset' => $src]];
    if ($s['local_target']['enabled'] && $s['local_target']['dataset'] !== '')
      $group[] = ['target' => 'local', 'dataset' => shive_target_for($s['local_target']['dataset'], $src)];
    if ($s['remote_target']['enabled'] && $s['remote_target']['spec'] !== '')
      $group[] = ['target' => $s['remote_target']['spec'], 'dataset' => shive_target_for($s['remote_target']['dataset'], $src)];
    // match the clicked dataset against this group, allowing for a child of a recursive schedule
    foreach ($group as $g) {
      if ($g['dataset'] === $ds) return $group;
      if ($s['recursive'] && str_starts_with($ds, $g['dataset'] . '/')) {
        $rel = substr($ds, strlen($g['dataset']));      // same child path in every location
        return array_map(fn($x) => ['target' => $x['target'], 'dataset' => $x['dataset'] . $rel], $group);
      }
    }
  }
  return [];
}

/* ---------------- cron ---------------- */
function shive_cron_expr(array $s): string {
  [$h, $m] = array_map('intval', explode(':', ($s['time'] ?: '03:00') . ':0'));
  switch ($s['frequency']) {
    case 'hourly':  return "$m * * * *";
    case 'daily':   return "$m $h * * *";
    case 'weekly':  return "$m $h * * " . (int)$s['weekday'];
    case 'monthly': return "$m $h " . max(1, min(28, (int)$s['monthday'])) . " * *";
    default:        return trim((string)$s['cron']);
  }
}
function shive_interval_seconds(array $s): ?int {
  return ['hourly' => 3600, 'daily' => 86400, 'weekly' => 604800, 'monthly' => 2678400][$s['frequency']] ?? null;
}
/* update_cron does NOT scan /boot/config/plugins/ blindly: it derives the directories to search
   from the symlinks in /var/log/plugins (Limetech's safe-mode fix). A plugin installed from a
   .plg whose name differs from our directory - e.g. a locally built "shive-local.plg" - therefore
   makes update_cron look in /boot/config/plugins/shive-local/ and never see our file. So: write
   the canonical copy into our own directory, then verify the entry actually reached
   /etc/cron.d/root, and if it didn't, fall back to the dynamix directory (always installed). */
function shive_cron_install(string $content): void {
  $primary  = SHIVE_PLG . '/shive.cron';
  $fallback = '/boot/config/plugins/dynamix/shive.cron';
  $wanted   = str_contains($content, 'shive-run');

  // /boot is a USB stick and this runs on every array start and every schedule save, so skip the
  // WRITE when the file already says exactly this. The verification below still runs every time:
  // /etc/cron.d/root can be stale for reasons that have nothing to do with our content (e.g. the
  // plugin was installed under a different .plg name, changing where update_cron even looks).
  if (!is_file($primary) || @file_get_contents($primary) !== $content) shive_atomic_write($primary, $content);
  if (is_file($fallback)) @unlink($fallback);      // start clean, re-added below only if needed
  exec('/usr/local/sbin/update_cron 2>/dev/null');

  if (!$wanted) return;                            // nothing scheduled: nothing to verify
  if (str_contains(@file_get_contents('/etc/cron.d/root') ?: '', 'shive-run')) return;

  shive_atomic_write($fallback, $content);
  exec('/usr/local/sbin/update_cron 2>/dev/null');
}
/* Regenerates the whole cron file from the schedule set -> idempotent by construction. */
function shive_cron_write(): void {
  $lines = ["# shive - generated, do not edit (Settings -> Shive)"];
  foreach (shive_schedules() as $s) {
    if (!$s['enabled']) continue;
    // snapshot job; sends whose location has its own schedule are skipped here
    $skip = '';
    foreach (['local_target' => ' --no-send-local', 'remote_target' => ' --no-send-remote'] as $k => $flag)
      if ($s[$k]['enabled'] && $s[$k]['own_schedule']) $skip .= $flag;
    // reference by id, not name: immune to name content, and a rename never needs a cron rewrite
    $lines[] = sprintf('%s %s/shive-run %s%s >/dev/null 2>&1', $s['cron_expr'], SHIVE_SCRIPTS, $s['id'], $skip);
    // separate send-only jobs
    foreach (['local_target' => 'local', 'remote_target' => 'remote'] as $k => $loc)
      if ($s[$k]['enabled'] && $s[$k]['own_schedule'])
        $lines[] = sprintf('%s %s/shive-run %s --send-only %s >/dev/null 2>&1', $s[$k]['cron_expr'], SHIVE_SCRIPTS, $s['id'], $loc);
  }
  shive_cron_install(implode("\n", $lines) . "\n");
}

/* ---------------- status ---------------- */
function shive_last_status(string $name): ?array {
  $f = SHIVE_PLG . "/state/$name.last.json";
  if (!is_file($f)) return null;
  $j = json_decode((string)file_get_contents($f), true);
  return is_array($j) ? $j : null;
}
function shive_running(string $name): ?array {
  $f = SHIVE_VAR . "/state/$name.json";
  if (!is_file($f)) return null;
  $j = json_decode((string)file_get_contents($f), true);
  if (!is_array($j) || ($j['phase'] ?? 'DONE') === 'DONE') return null;
  if (!file_exists('/proc/' . (int)$j['pid'])) return null;
  return $j;
}
/* Aggregated status for the dashboard tile; written to /var/local/shive/status.json. */
function shive_status_rebuild(): array {
  $now = time(); $health = 'ok'; $items = [];
  $rank = ['ok' => 0, 'warning' => 1, 'error' => 2];
  foreach (shive_schedules() as $s) {
    $run = shive_running($s['id']); $last = shive_last_status($s['id']);
    $h = 'ok'; $msgs = [];
    if ($run) { $h = 'ok'; $msgs[] = 'running (' . $run['phase'] . ')'; }
    elseif (!$last) { $h = $s['enabled'] ? 'warning' : 'ok'; $msgs[] = 'never ran'; }
    else {
      if (($last['status'] ?? '') === 'failed' || ($last['status'] ?? '') === 'crashed') $h = 'error';
      elseif (($last['status'] ?? '') === 'warning') $h = 'warning';
      if (!empty($last['resume_failed'])) { $h = 'error'; $msgs[] = 'containers DOWN: ' . implode(', ', $last['resume_failed']); }
      // locations with their own schedule report through their own send-only run
      foreach (['local_target' => 'local', 'remote_target' => 'remote'] as $k => $loc) {
        if (!($s[$k]['enabled'] && $s[$k]['own_schedule'])) continue;
        $sl = shive_last_status($s['id'] . '.send-' . $loc);
        $last['sends'][$loc] = $sl ? ['status' => $sl['status'] === 'success' ? 'ok' : $sl['status'],
                                      'finished' => $sl['finished'], 'snapshot' => $sl['snapshot'] ?? ''] : ['status' => 'never ran'];
        if (($last['sends'][$loc]['status'] ?? '') === 'failed') $h = 'error';
      }
      $iv = shive_interval_seconds($s);
      if ($s['enabled'] && $iv && strtotime($last['finished'] ?? '1970-01-01') < $now - 2 * $iv) { if ($rank[$h] < $rank['warning']) $h = 'warning'; $msgs[] = 'overdue'; }
      foreach ($last['sends'] ?? [] as $loc => $snd) if (($snd['status'] ?? '') !== 'ok') $msgs[] = "$loc send " . ($snd['status'] ?? '?');
    }
    if ($rank[$h] > $rank[$health]) $health = $h;
    $items[] = [
      'name' => $s['name'], 'id' => $s['id'], 'enabled' => $s['enabled'], 'health' => $h, 'messages' => $msgs,
      'running' => $run ? ['phase' => $run['phase'], 'started' => $run['started']] : null,
      'last' => $last ? ['status' => $last['status'], 'finished' => $last['finished'], 'snapshot' => $last['snapshot'],
                          'containers' => $last['containers'] ?? [], 'resume_failed' => $last['resume_failed'] ?? [],
                          'sends' => $last['sends'] ?? [], 'errors' => $last['errors'] ?? [], 'warnings' => $last['warnings'] ?? []] : null,
      'docker_aware' => $s['docker_aware'],
      'local_target' => $s['local_target']['enabled'] ? $s['local_target']['dataset'] : null,
      'remote_target' => $s['remote_target']['enabled'] ? $s['remote_target']['host'] . ':' . $s['remote_target']['dataset'] : null,
    ];
  }
  $st = ['health' => $health, 'updated' => date('c'), 'schedules' => $items];
  shive_atomic_write(SHIVE_VAR . '/status.json', json_encode($st, JSON_UNESCAPED_SLASHES));
  return $st;
}
