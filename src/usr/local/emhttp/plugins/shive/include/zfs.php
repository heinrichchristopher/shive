<?php
/* shive – ZFS listing helpers, local and via ssh (read-only operations only;
   anything destructive lives in the shell scripts). */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function zfs_pools(): array {
  $out = [];
  foreach (explode("\n", shive_run('zpool list -H -o name,health,size,free')) as $l) {
    if ($l === '') continue;
    [$n, $h, $s, $f] = array_pad(preg_split('/\t/', $l), 4, '');
    $out[$n] = ['name' => $n, 'health' => $h, 'size' => $s, 'free' => $f];
  }
  return $out;
}
function zfs_datasets(): array {
  $out = [];
  foreach (explode("\n", shive_run('zfs list -H -t filesystem -o name,mountpoint,mounted,used,avail,encryption -s name')) as $l) {
    if ($l === '') continue;
    [$n, $mp, $m, $u, $a, $e] = array_pad(preg_split('/\t/', $l), 6, '');
    $out[$n] = ['name' => $n, 'mountpoint' => $mp, 'mounted' => $m === 'yes', 'used' => $u, 'avail' => $a, 'encrypted' => $e !== 'off'];
  }
  return $out;
}
/* ssh prefix for a spec ssh://user@host:port/ds ; returns [prefix, dataset] */
function zfs_ssh(string $spec): array {
  if (!preg_match('#^ssh://(?:([^@/]+)@)?([^:/]+)(?::(\d+))?/(.+)$#', $spec, $m)) throw new RuntimeException("bad spec $spec");
  $c = shive_cfg();
  $key = $c['SSH_KEY'] !== '' ? ' -i ' . escapeshellarg($c['SSH_KEY']) : '';
  $pre = sprintf('ssh %s%s -p %d %s@%s', $c['SSH_OPTS'], $key, (int)($m[3] ?: 22), escapeshellarg($m[1] ?: 'root'), escapeshellarg($m[2]));
  return [$pre, $m[4]];
}
function zfs_exec(string $target, string $cmd, ?int &$rc = null): string {
  if ($target === 'local' || $target === '') return shive_run($cmd, $rc);
  [$pre] = zfs_ssh($target);
  return shive_run($pre . ' ' . escapeshellarg($cmd), $rc);
}
/* snapshots of $ds (optionally whole tree), newest first */
function zfs_snapshots(string $ds, string $target = 'local', bool $recursive = false, string $prefix = 'shive-'): array {
  static $byId = null;
  if ($byId === null) { $byId = []; foreach (shive_schedules() as $sc) $byId[$sc['id']] = $sc['name']; }   // hex id: string key, never (int)
  $r = $recursive ? '-r' : '-d 1';
  $raw = zfs_exec($target, "zfs list -H -p $r -t snapshot -o name,creation,used,referenced,shive:important -S creation " . escapeshellarg($ds), $rc);
  if ($rc !== 0) return [];
  $out = [];
  foreach (explode("\n", $raw) as $l) {
    if ($l === '') continue;
    [$n, $c, $u, $ref, $imp] = array_pad(preg_split('/\t/', $l), 5, '');
    [$dsn, $sn] = explode('@', $n, 2);
    if ($prefix !== '' && !str_starts_with($sn, $prefix)) continue;
    $sid = preg_match('/^shive-([0-9a-f]{6})-/', $sn, $mm) ? $mm[1] : null;
    $sched = $sid !== null ? ($byId[$sid] ?? "#$sid (deleted)") : (str_starts_with($sn, 'shive-prerestore-') ? 'pre-restore' : null);
    $out[] = ['dataset' => $dsn, 'name' => $sn, 'full' => $n, 'creation' => (int)$c, 'used' => (int)$u, 'referenced' => (int)$ref,
              'schedule' => $sched, 'schedule_id' => $sid, 'important' => ($imp !== '' && $imp !== '-')];
  }
  return $out;
}
/* directory listing inside a staged snapshot path (local or remote) */
function zfs_browse(string $path, string $target = 'local'): array {
  if (str_contains($path, '..')) return [];
  $cmd = "find " . escapeshellarg($path) . " -mindepth 1 -maxdepth 1 -printf '%y\\t%s\\t%T@\\t%f\\n' 2>/dev/null | sort -k4";
  $out = [];
  foreach (explode("\n", zfs_exec($target, $cmd)) as $l) {
    if ($l === '') continue;
    [$t, $s, $m, $n] = array_pad(explode("\t", $l, 4), 4, '');
    $out[] = ['name' => $n, 'dir' => $t === 'd', 'size' => (int)$s, 'mtime' => (int)$m];
  }
  return $out;
}
