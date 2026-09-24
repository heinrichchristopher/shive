<?php
/* shive – CLI entry point used by the shell scripts (single source of truth shared with the GUI).
   php cli.php <command> [args]
     schedule-get <id|name>          print validated schedule JSON (exit 1 if missing)
     cron-write                     regenerate /boot/config/plugins/shive/shive.cron + update_cron
     status-rebuild                 aggregate last-status files -> /var/local/shive/status.json
     linked <id|name>                containers linked to a schedule  -> [{name,running}]
     linked-dataset <dataset>       containers linked to one dataset -> [{name,running}]
     locations <ds> <snap>          every location holding that snapshot (source + targets)
     discover [--refresh]           container/dataset discovery (cached 5 min)
     retention --policy <json>      stdin: "name<TAB>creation-epoch" lines -> {keep,destroy,...}
     catchup                        run schedules whose last run is older than their interval */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/zfs.php';
require_once __DIR__ . '/docker.php';
require_once __DIR__ . '/retention.php';

$ident = @parse_ini_file('/boot/config/ident.cfg') ?: [];
date_default_timezone_set($ident['timeZone'] ?? 'UTC');

$cmd = $argv[1] ?? '';
$out = static fn($v) => print(json_encode($v, JSON_UNESCAPED_SLASHES) . "\n");

switch ($cmd) {
  case 'schedule-get':
    $s = shive_schedule_resolve($argv[2] ?? '');
    if (!$s) { fwrite(STDERR, "schedule not found\n"); exit(1); }
    $out($s); break;
  case 'cron-write': shive_cron_write(); break;
  case 'status-rebuild': $out(shive_status_rebuild()); break;
  case 'linked':
    $s = shive_schedule_resolve($argv[2] ?? '');
    if (!$s) { fwrite(STDERR, "schedule not found\n"); exit(1); }
    $out(docker_linked($s['datasets'], $s['recursive'], true, $s['exclude_datasets'])); break;
  case 'linked-dataset': $out(docker_linked([$argv[2] ?? ''], true)); break;
  case 'locations':   // <dataset> <snapshot-name> -> every copy of that snapshot
    $out(shive_snapshot_locations($argv[2] ?? '', $argv[3] ?? '')); break;
  case 'discover': $out(docker_discover(in_array('--refresh', $argv, true))); break;
  case 'retention':
    $i = array_search('--policy', $argv, true);
    $policy = json_decode($argv[$i + 1] ?? '{}', true);
    if (!is_array($policy)) { fwrite(STDERR, "bad policy\n"); exit(1); }
    $snaps = [];
    while (($l = fgets(STDIN)) !== false) {
      $l = trim($l); if ($l === '') continue;
      [$n, $c, $imp] = array_pad(explode("\t", $l, 3), 3, '');
      // zfs prints '-' for an unset user property
      $snaps[] = ['name' => $n, 'creation' => (int)$c, 'important' => ($imp !== '' && $imp !== '-')];
    }
    $out(retention_plan($snaps, $policy)); break;
  case 'catchup':
    if ((shive_cfg()['CATCHUP_ON_START'] ?? 'yes') !== 'yes') break;
    foreach (shive_schedules() as $s) {
      if (!$s['enabled'] || !($iv = shive_interval_seconds($s))) continue;
      $last = shive_last_status($s['id']);
      // A schedule that has never run is "overdue" only relative to when it was CREATED, not
      // infinitely so - the old PHP_INT_MAX fallback made every brand-new schedule fire on the
      // very next reboot, regardless of whether its scheduled time had even been reached yet.
      $age = $last ? time() - (int)strtotime($last['finished'] ?? '1970-01-01') : time() - (int)($s['created'] ?: time());
      if ($age > $iv) {
        shive_run('logger -t shive ' . escapeshellarg("catch-up: starting overdue schedule {$s['name']} [{$s['id']}]"));
        shive_run('nohup ' . SHIVE_SCRIPTS . '/shive-run ' . $s['id'] . ' >/dev/null 2>&1 &');
      }
    }
    break;
  default: fwrite(STDERR, "unknown command '$cmd'\n"); exit(2);
}
