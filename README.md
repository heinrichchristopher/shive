<img src="icons/shive-color.png" width="96" align="right" alt="Shive">

# Shive – ZFS snapshot scheduling, retention & replication for Unraid 7.2+

[![CI](https://github.com/heinrichchristopher/shive/actions/workflows/ci.yml/badge.svg)](https://github.com/heinrichchristopher/shive/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

**Install** (Unraid → Plugins → Install Plugin):

```
https://raw.githubusercontent.com/heinrichchristopher/shive/main/shive.plg
```

*Deutsche Fassung: [README.de.md](README.de.md)*

Shive takes scheduled ZFS snapshots of any dataset, prunes them by age or GFS policy (hourly/daily/weekly/monthly)
per location, optionally quiesces the Docker containers whose bind-mounts live on the
snapshotted datasets, replicates to a second local pool and/or an SSH host, and lets you
browse and restore files, folders or whole datasets from the WebGUI.

* Snapshot tag: `shive-<hex>-DDMMYYYY-HHMM` – a random 6-hex-digit ID assigned when the schedule is
  created, checked against every ID ever handed out (`/boot/config/plugins/shive/used_ids`) so it's never
  reused. Cron, state, locks, logs and the schedule file itself (`schedules/<id>.json`) are all keyed by this
  ID, so the display name is free text (any printable characters, up to 64, need not be unique) and renaming
  touches nothing but one JSON field. Same-minute snapshot-name collisions get a `-SS` suffix.
* Replication: `zfs send -I` (intermediates included) → target keeps its own full history;
  local/remote target fields take a **root** dataset, not a literal destination - each source
  lands underneath as `<root>/<basename>`, so `zfs receive` never writes into an existing dataset;
  a bookmark on the source keeps the incremental base alive even after source pruning.
* Containers are restarted **immediately after the snapshot**, not after the sends,
  and the restart runs from an `EXIT` trap plus a crash-recovery pass (`shive-recover`).
* Nothing destructive happens without `--yes` / an explicit GUI confirmation; every job has a dry-run.

Requires Unraid ≥ 7.2 (responsive WebGUI, ZFS 2.3). Binaries used: `zfs zpool jq docker php rsync flock findmnt`.

## Layout

```
shive.plg                       installer manifest
build.sh                        builds the .txz package and patches version/MD5 into shive.plg
src/usr/local/emhttp/plugins/shive/
  Shive*.page                   WebGUI (Settings → Shive; tabs) + ShiveDashboard.page (tile)
  include/config.php            schedules, cron generation, status aggregation
  include/zfs.php               read-only zfs/zpool listing, local + ssh
  include/docker.php            container → dataset discovery, manual overrides
  include/retention.php         age / GFS classification (pure function)
  include/cli.php               CLI used by the scripts (shared logic with the GUI)
  include/api.php               AJAX endpoint
  scripts/shive-run             job runner (state machine, trap-based container resume)
  scripts/shive-send            send/receive abstraction (local:… or ssh://…)
  scripts/shive-prune           retention executor
  scripts/shive-restore         stage / file / dataset / DR restore
  scripts/shive-recover         crash safety net, clone cleanup
  scripts/lib/{common,guard-none,guard-docker}.sh
  event/{started,docker_started,stopping_svcs,unmounting_disks}   Unraid array lifecycle hooks
docs/ARCHITECTURE.md            design + decisions
docs/TESTING.md                 how to test on a live box without risking data
icons/                          logo sources (colour = plugin manager/README, line-art = Settings page + dashboard tile)
```

Persistent config: `/boot/config/plugins/shive/` (`shive.cfg`, `schedules/*.json`, `mappings.json`,
`state/*.last.json`, generated `shive.cron`). Runtime state: `/var/local/shive/`. Logs: `/var/log/shive/`.

## CLI cheat sheet

```
shive-run <id|name> [--dry-run] [--force] [--no-prune]
          [--send-only local|remote]      # no snapshot: send the newest existing one
          [--no-send | --no-send-local | --no-send-remote]
shive-send --sched s --from pool/ds@snap --to local:pool2/ds | ssh://user@host:22/pool/ds [--recursive] [--dry-run]
shive-prune --sched s --location source|local|remote --dataset ds [--target ssh://…] [--recursive] [--dry-run] [--json]
shive-snapshot flag|unflag|destroy --snapshot <ds@snap> [--target …] [--recursive] [--yes] [--dry-run]
shive-restore stage|unstage|file|dataset|dr …          (see header of the script)
shive-recover [--quiet]
shive-discover [--refresh]
```

## Releasing

The package MD5 lives in the committed `shive.plg`, because that is the file users install from.
So the version must be built and committed *before* it is tagged:

```bash
./build.sh 2026.09.12          # patches version + MD5 into shive.plg, builds build/<pkg>.txz
git commit -am "release 2026.09.12"
git push
git tag 2026.09.12 && git push origin 2026.09.12
```

The release workflow rebuilds from the tag and refuses to publish if the committed `shive.plg`
doesn't match, so a mismatched checksum fails the release instead of reaching users. Packages are
byte-reproducible (fixed sort order, mtime and ownership), which is what makes that check meaningful.

## Third-party assets

The logo is the "Cask" icon by [justicon](https://www.flaticon.com/authors/justicon) from
[Flaticon](https://www.flaticon.com/free-icon/cask_2617498), used under their Free License
(attribution required) - see [NOTICE.md](NOTICE.md).

## Contributing / running the tests

`bash test/run.sh` runs 109 checks against a stateful ZFS/docker/ssh simulator - no Unraid and no
real pools required. It needs `php-cli`, `jq`, `rsync` and `python3`, and should be run as root in
a throwaway container, since it writes under `/boot`, `/var` and `/mnt`. CI runs it on every push.
