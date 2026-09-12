<?php
/* shive – container -> dataset discovery.
   docker inspect bind mounts -> normalise /mnt/user paths -> findmnt -T -> zfs dataset.
   Cached in /var/local/shive/discovery.json (TTL 300s); manual overrides in mappings.json. */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

const SHIVE_DISCOVERY = SHIVE_VAR . '/discovery.json';

function docker_mappings(): array {
  $m = json_decode((string)@file_get_contents(SHIVE_PLG . '/mappings.json'), true);
  return is_array($m) ? $m : [];
}
function docker_mappings_save(array $m): void {
  shive_atomic_write(SHIVE_PLG . '/mappings.json', json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
/* resolve one bind-mount source path to a zfs dataset name (or null) */
function docker_path_to_dataset(string $path, array $pools): ?string {
  $candidates = [$path];
  if (preg_match('#^/mnt/user0?/(.*)$#', $path, $m)) {
    $candidates = [];
    foreach ($pools as $p) $candidates[] = "/mnt/$p/" . $m[1];
  }
  foreach ($candidates as $c) {
    if (!file_exists($c)) continue;
    $r = trim(shive_run('findmnt -no SOURCE,FSTYPE -T ' . escapeshellarg($c), $rc));
    if ($rc !== 0 || $r === '') continue;
    [$src, $fs] = array_pad(preg_split('/\s+/', $r), 2, '');
    // findmnt shows "dataset[/subpath]" when the mounted point is a bind-mount of a
    // subdirectory within the dataset (e.g. Unraid binding /var/lib/docker from
    // <pool>/system/docker) rather than the dataset's own root - the real dataset name is
    // everything before the bracket.
    $src = preg_replace('/\[.*$/', '', $src);
    if ($fs === 'zfs') return $src;
  }
  return null;
}
function docker_discover(bool $refresh = false): array {
  if (!$refresh && is_file(SHIVE_DISCOVERY) && time() - filemtime(SHIVE_DISCOVERY) < 300) {
    $c = json_decode((string)file_get_contents(SHIVE_DISCOVERY), true);
    if (is_array($c)) return $c;
  }
  $pools = array_keys(zfs_pools_simple());
  $psOut = shive_run("docker ps -a --format '{{.Names}}'", $psRc);
  $names = $psRc === 0 ? array_filter(explode("\n", $psOut)) : [];   // daemon down -> no containers, not garbage
  $containers = [];
  if ($names) {
    $raw = shive_run("docker inspect --format '{{.Name}}|SHIVE|{{.State.Running}}|SHIVE|{{json .Mounts}}' " . implode(' ', array_map('escapeshellarg', $names)));
    foreach (explode("\n", $raw) as $l) {
      if ($l === '' || !str_contains($l, '|SHIVE|')) continue;
      [$name, $running, $mounts] = array_pad(explode('|SHIVE|', $l, 3), 3, '');
      $name = ltrim($name, '/');
      $ds = []; $paths = [];
      foreach (json_decode($mounts, true) ?: [] as $mt) {
        if (($mt['Type'] ?? '') !== 'bind') continue;
        $paths[] = $mt['Source'];
        $d = docker_path_to_dataset($mt['Source'], $pools);
        if ($d) $ds[$d] = true;
      }
      $containers[$name] = ['name' => $name, 'running' => $running === 'true', 'datasets' => array_keys($ds), 'mounts' => $paths, 'override' => null];
    }
  }
  foreach (docker_mappings() as $name => $ov) {
    if (!isset($containers[$name])) $containers[$name] = ['name' => $name, 'running' => false, 'datasets' => [], 'mounts' => [], 'override' => null, 'missing' => true];
    if (!empty($ov['ignore'])) { $containers[$name]['datasets'] = []; $containers[$name]['override'] = 'ignore'; }
    elseif (!empty($ov['datasets'])) { $containers[$name]['datasets'] = array_values($ov['datasets']); $containers[$name]['override'] = 'manual'; }
  }
  ksort($containers);
  $byDs = [];
  foreach ($containers as $c) foreach ($c['datasets'] as $d) $byDs[$d][] = $c['name'];
  $result = ['updated' => time(), 'containers' => $containers, 'datasets' => $byDs];
  shive_atomic_write(SHIVE_DISCOVERY, json_encode($result, JSON_UNESCAPED_SLASHES));
  return $result;
}
function zfs_pools_simple(): array {
  $o = [];
  foreach (array_filter(explode("\n", shive_run('zpool list -H -o name'))) as $p) $o[$p] = true;
  return $o;
}
/* containers linked to a dataset set (recursive => children count too) -> [{name,running}] */
function docker_linked(array $datasets, bool $recursive, bool $refresh = true): array {
  $disc = docker_discover($refresh);
  $out = [];
  foreach ($disc['containers'] as $c) {
    if (!empty($c['missing'])) continue;
    foreach ($c['datasets'] as $d) foreach ($datasets as $t) {
      if ($d === $t || ($recursive && str_starts_with($d, $t . '/'))) { $out[] = ['name' => $c['name'], 'running' => $c['running']]; continue 3; }
    }
  }
  return $out;
}
