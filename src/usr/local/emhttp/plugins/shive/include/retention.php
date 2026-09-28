<?php
/* shive – retention classification. Pure function: snapshots + policy in, keep/destroy out.
   Age is always taken from the zfs `creation` property (epoch), never from the name,
   so DST changes and dataset renames cannot misclassify anything.
   The newest snapshot is always kept (it is the replication base by construction). */
declare(strict_types=1);

/**
 * @param array $snaps  [['name'=>string,'creation'=>int,'important'=>bool], ...]
 * Snapshots flagged important (ZFS user property shive:important) are never proposed for
 * deletion and do not consume a GFS tier slot - they sit outside the policy entirely.
 * @param array $policy ['mode'=>'age'|'gfs','days'=>int,'hourly'=>int,'daily'=>int,'weekly'=>int,'monthly'=>int]
 * GFS tiers count distinct calendar buckets that contain snapshots (newest per bucket survives),
 * not elapsed time: hourly=24 keeps the newest snapshot of each of the last 24 hours that have one.
 */
function retention_plan(array $snaps, array $policy, ?DateTimeZone $tz = null, ?int $now = null): array {
  $tz ??= new DateTimeZone(date_default_timezone_get());
  $now ??= time();
  usort($snaps, fn($a, $b) => $b['creation'] <=> $a['creation']);   // newest first
  $keep = []; $destroy = []; $reason = [];
  if (!$snaps) return ['keep' => [], 'destroy' => [], 'reasons' => [], 'policy_summary' => 'empty'];
  $newest = $snaps[0]['name'];
  // important snapshots are set aside before the policy runs, so they neither get deleted
  // nor occupy a daily/weekly/monthly slot that a prunable snapshot could have used
  $flagged = [];
  foreach ($snaps as $i => $s) if (!empty($s['important'])) { $flagged[] = $s['name']; unset($snaps[$i]); }
  $snaps = array_values($snaps);
  foreach ($flagged as $n) { $keep[] = $n; $reason[$n] = 'important'; }
  if (!$snaps) return ['keep' => $keep, 'destroy' => [], 'reasons' => $reason, 'policy_summary' => 'all important'];

  if (($policy['mode'] ?? 'gfs') === 'age') {
    $limit = $now - max(0, (int)$policy['days']) * 86400;
    foreach ($snaps as $s) {
      if ($s['name'] === $newest || $s['creation'] >= $limit) { $keep[] = $s['name']; $reason[$s['name']] = $s['name'] === $newest ? 'newest' : 'within ' . $policy['days'] . 'd'; }
      else $destroy[] = $s['name'];
    }
    $summary = 'age: keep ' . $policy['days'] . ' days';
  } else {
    $quota = ['hourly' => (int)($policy['hourly'] ?? 0), 'daily' => (int)$policy['daily'], 'weekly' => (int)$policy['weekly'], 'monthly' => (int)$policy['monthly']];
    $claimed = ['hourly' => [], 'daily' => [], 'weekly' => [], 'monthly' => []];
    foreach ($snaps as $s) {
      $d = (new DateTimeImmutable('@' . $s['creation']))->setTimezone($tz);
      $keys = ['hourly' => $d->format('Y-m-d H'), 'daily' => $d->format('Y-m-d'), 'weekly' => $d->format('o-\WW'), 'monthly' => $d->format('Y-m')];
      $why = [];
      if ($s['name'] === $newest) $why[] = 'newest';
      foreach ($keys as $tier => $k) {
        // first (=newest) snapshot seen in a bucket claims it, while the tier still has quota
        if (!isset($claimed[$tier][$k]) && count($claimed[$tier]) < $quota[$tier]) { $claimed[$tier][$k] = $s['name']; $why[] = "$tier $k"; }
      }
      if ($why) { $keep[] = $s['name']; $reason[$s['name']] = implode(', ', $why); } else $destroy[] = $s['name'];
    }
    $summary = sprintf('gfs: %dh/%dd/%dw/%dm', $quota['hourly'], $quota['daily'], $quota['weekly'], $quota['monthly']);
  }
  return ['keep' => $keep, 'destroy' => $destroy, 'reasons' => $reason, 'policy_summary' => $summary];
}
