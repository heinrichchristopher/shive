<img src="icons/shive-color.png" width="96" align="right" alt="Shive">

# Shive – ZFS snapshot scheduling, retention & replication for Unraid 7.2+

[![CI](https://github.com/heinrichchristopher/shive/actions/workflows/ci.yml/badge.svg)](https://github.com/heinrichchristopher/shive/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

**Install** (Unraid → Plugins → Install Plugin):

```
https://raw.githubusercontent.com/heinrichchristopher/shive/main/shive.plg
```

*Deutsche Fassung: [README.de.md](README.de.md)*

Shive takes scheduled ZFS snapshots of any dataset, prunes them by age or GFS policy, optionally
quiesces the Docker containers whose bind-mounts live on the snapshotted datasets, replicates to a
second local pool and/or an SSH host, and lets you browse and restore files, folders or whole
datasets from the WebGUI.

**Development.** Built in close collaboration with Claude (Anthropic). Architecture decisions,
all testing on real hardware (a live Unraid 7.3.2 box with real ZFS pools and Docker containers),
and every design and security call were mine; Claude wrote code under that direction, ran a
109-check regression suite against a stateful simulator, and went through six dedicated QC passes.

---

## Contents

- [Why Shive](#why-shive)
- [Installation](#installation)
- [Features](#features)
  - [Schedules](#schedules)
  - [Snapshot naming and schedule ID](#snapshot-naming-and-schedule-id)
  - [Docker awareness](#docker-awareness)
  - [Replication](#replication)
  - [Retention](#retention)
  - [Snapshot browser and restore](#snapshot-browser-and-restore)
  - [Dashboard tile and notifications](#dashboard-tile-and-notifications)
- [How a run works](#how-a-run-works)
- [Safety nets](#safety-nets)
- [Command line](#command-line)
- [Files and locations](#files-and-locations)
- [First-time setup – recommended path](#first-time-setup--recommended-path)
- [Known limitations](#known-limitations)
- [Requirements](#requirements)
- [Repository layout](#repository-layout)
- [Releasing](#releasing)
- [Third-party assets](#third-party-assets)
- [Contributing / running the tests](#contributing--running-the-tests)

---

## Why Shive

Unraid ships with ZFS, but no built-in interface for snapshots, retention and replication. Shive
fills that gap, with three things it particularly cares about:

- **A failed backup never leaves a container down.** The restart is anchored on an `EXIT` trap
  and backed up by a recovery pass that still catches it after a power loss.
- **Pruning can only ever touch its own snapshots.** Every schedule has a fixed ID, and retention
  filters strictly on that ID's naming pattern.
- **Nothing destructive without explicit confirmation**, and every job has a dry-run mode.

---

## Installation

Via Unraid's Plugins page, using the URL above.

For a local test without a GitHub release:

```bash
./build.sh                                   # builds the .txz, validates and updates shive.plg,
                                             # also writes build/local/shive.plg (a file:// URL)
mkdir -p /boot/config/plugins/shive
cp build/shive-*.txz /boot/config/plugins/shive/
cp build/local/shive.plg /boot/config/plugins/
plugin install /boot/config/plugins/shive.plg
```

The local `.plg` **must** be named `shive.plg`: Unraid's `update_cron` only scans directories
named after the installed plugin (derived from `/var/log/plugins`). A different name like
`shive-local.plg` would leave the cron file unnoticed - Shive falls back to the dynamix directory
and warns in the Schedules tab in that case, but the correct name is cleaner.

Afterwards reachable at **Settings → Shive**. Uninstalling deliberately leaves schedules and
settings under `/boot/config/plugins/shive/` in place.

---

## Features

### Schedules

A schedule consists of one or more source datasets, a frequency, and retention rules. Multiple
independent schedules are supported.

| Setting | Meaning |
|---|---|
| **Datasets** | Multi-select with a filter field. Optionally recursive, in which case child datasets are included in the same, simultaneous snapshot. Individual child datasets can be excluded from that (see below). |
| **Frequency** | hourly, daily, weekly, monthly, or a custom cron expression |
| **Name** | free-form text up to 64 characters, changeable any time - slashes, umlauts and special characters are fine, e.g. `pin/kilderkin – data`. Names need not be unique. |
| **Enabled** | disabled schedules produce no cron entry |

Cron entries are regenerated in full on every save, so duplicates cannot occur. Renaming only
moves the schedule file; history, logs and the cron entry stay put, because they're keyed by the
ID, not the name.

### Exclusions

Recursive schedules can leave individual child datasets out - useful for large, re-downloadable
data such as model files or caches. Two levels:

- **Exclude from the snapshot** - the dataset gets no snapshot at all. Saves space on the source,
  but leaves you without a local restore point for it.
- **Additionally skip when sending**, configured per target - the snapshot is still taken (local
  restore point intact), only that target doesn't receive it. Typical case: keep it on the fast
  local SSD, but don't push it over the wire to the remote.

The per-target lists are **additive** to the snapshot exclusions: something that never gets a
snapshot can't be replicated anywhere anyway. Excluding a dataset always excludes everything
below it too. Only child datasets that actually exist under the selected sources are offered -
otherwise a typo would silently exclude nothing.

Under the hood: since `zfs snapshot -r` has no exclude option, Shive passes every wanted dataset
to a single `zfs snapshot` call when exclusions are in play - that form is atomic too, so all
datasets keep the identical point in time. Sending drops `-R` for the same reason and transfers
each dataset on its own (parents before children), incrementally as before.

### Snapshot naming and schedule ID

Every schedule gets a random six-digit hex ID when it's created. Snapshots are named:

```
kilderkin/archive@shive-5f946c-09092026-0300
                  └──┬──┘ └──┬─┘ └────┬────┘
                  prefix    ID   date-time
```

The ID is **immutable** and **never reused** - not even after a schedule is deleted. Every ID ever
handed out is recorded in `used_ids`. Two benefits follow:

- Renaming a schedule leaves its existing snapshots untouched.
- A newly created schedule can never inherit and prune an old one's snapshots.

If a manual run lands in the same minute as an existing snapshot, Shive appends the seconds
(`-SS`) once rather than letting the run fail.

### Docker awareness

Enabled per schedule. When on, Shive determines which containers have their data on the target
datasets, stops **only the ones currently running**, takes the snapshot, and starts **exactly
those** back up - immediately after the snapshot, not after replication. Downtime is therefore
seconds, even if the subsequent transfer takes an hour.

The container → dataset mapping is discovered automatically:

```
docker inspect  →  bind-mount paths
                →  /mnt/user/... is rewritten to /mnt/<pool>/...
                →  findmnt -T <path>  →  the actual dataset
```

The result is visible in the **Containers** tab. If a path can't be resolved, the dataset can be
entered there manually; containers that must never be stopped can be set to "ignore".

Without Docker awareness the snapshot runs straight through - containers are never touched.

**Start order and wait.** Each container can be given a *start order* and a *wait after start* in
the Containers tab. Containers come back up in ascending order (0 = unspecified, name-sorted among
equals) and are **stopped in the exact reverse order**, since whatever starts last depends on what
came before it: `paperless-redis = 10`, `paperless-ngx = 20` starts redis first and stops the app
first. The wait pauses that many seconds before the next container starts, for a dependency that
needs a moment to become ready; it is skipped after the last one. Both are properties of the
container, so they apply to every schedule it appears in.

### Replication

Two independent targets per schedule:

- **Local backup target** - a dataset on another pool, typically a separate SSD.
- **Remote backup target** - an SSH host (`user@host:port/pool/dataset`).

Both take only a **root** (e.g. `pool/backups`), never a literal destination path. Shive creates
one target dataset per source dataset underneath it automatically, named after the source's last
path component: source `pin/appdata` + root `minikeg/backup` yields the actual target
`minikeg/backup/appdata`. This lets several schedules share one root without colliding, and
`zfs receive` never has to write into a dataset that already exists (which ZFS refuses outright).
If the root itself doesn't exist yet, the editor shows a one-click "create it now" hint - it only
ever creates the root, never the target dataset itself, which comes into being naturally on the
first real transfer.

Both targets receive snapshots via `zfs send -I`. The `-I` transfers **every intermediate
snapshot** since the target's last known state - a weekly sync of daily snapshots therefore
brings across all seven, not just the newest. The target ends up with full, independent snapshots
and its own retention; if the source pool is lost, the complete history still exists there.

**An independent schedule per target** (optional): daily snapshots, but only sync to the remote
host on Sundays. Shive then generates two cron lines - the snapshot job skips that target, a
separate send job catches it up. The send job takes **no** new snapshot and touches **no**
containers - it uses the newest one that already exists.

How the base for an incremental send is chosen:

1. Interrupted stream on the target? → resume with `zfs send -t <token>`
2. Newest shared snapshot → `-I` from there
3. Otherwise: bookmark on the source → `-i` from there
4. Target doesn't exist yet → full send
5. Target exists but shares nothing → abort with a clear message (never a silent overwrite)

The **bookmark** is why source retention and replication never conflict: it takes up no space,
but survives the snapshot's deletion and continues to serve as the base.

Received with `-u -s -o readonly=on` and without `mountpoint`, `canmount`, `sharenfs`, `sharesmb`,
so the backup never mounts over live data and a later browse doesn't block the next sync.

### Retention

Configurable independently for **source**, **local target** and **remote target**. Two modes:

| Mode | Behaviour |
|---|---|
| **Age** | deletes everything older than N days |
| **GFS** | keeps the newest snapshot per hour / day / calendar week / month |

GFS tiers count **calendar buckets that contain snapshots**, not elapsed time. "7 daily" means:
the newest snapshot from each of the last seven days *that have a snapshot at all*. If the server
was off for three days, this creates no gaps - the tiers count what exists. Because a snapshot
belongs to its calendar day permanently, the classification also always comes out the same
regardless of when it's checked, and daylight saving transitions don't matter.

The **hourly tier** defaults to 0 and only makes sense for hourly (or more frequent) schedules: on
a daily schedule, "24 hourly" would simply keep 24 daily snapshots, since each falls in its own
hour.

In general:

- **Only** snapshots carrying the schedule's own prefix are ever considered.
- The newest snapshot is always kept.
- **Snapshots marked important (★) are never deleted** and don't consume a GFS slot either -
  they sit entirely outside the policy.
- Age comes from the ZFS `creation` property, never from the name.
- The **Prune preview** button shows, before anything is actually pruned, whether each snapshot
  would be kept or deleted - and which tier is holding it. Nothing is deleted by the preview.

### Snapshot browser and restore

The **Snapshots & Restore** tab lists and browses snapshots from the source, the local target and
the remote target.

- Mounted datasets are read directly via `.zfs/snapshot/<name>/` - no clone, nothing to clean up.
- Unmounted datasets (backup targets) get a read-only clone from Shive, removed automatically on
  close or once its TTL expires.
- Child datasets show up as empty folders inside their parent's snapshot - their data lives in
  their own snapshots. Shive flags this and links straight there.

Individual snapshots can also be managed directly from there:

- **★ Mark as important** - the snapshot is never touched by retention. Stored as the ZFS
  property `shive:important` on the snapshot itself, so it's visible outside Shive too
  (`zfs get shive:important …`). The flag is applied to **every copy immediately** - source,
  local target and remote - because each location prunes independently. If a target is
  unreachable at the time, that's reported and reconciled automatically on the next replication
  run (the source is authoritative). The click works in both directions: setting it on a target
  copy also lands on the source.
- **Delete** - a single snapshot, optionally including the same-named snapshots on child
  datasets. Requires explicit confirmation; if the snapshot still has a clone (e.g. an open
  browse view), Shive aborts with a clear message instead of a raw ZFS error.

Three restore paths:

| Path | Behaviour |
|---|---|
| **File / folder** | `rsync -aHAX`; **as a copy** next to the target by default (`<dest>.shive-restore-<timestamp>`), overwrite only after explicit confirmation |
| **Whole dataset** | Default: rsync copy-back (snapshot history is preserved). Advanced: `zfs rollback -r` - fast, but destroys every newer snapshot |
| **Disaster recovery (DR)** | receives a snapshot from a backup target into a **new** dataset; live data is never touched, the swap afterwards is a deliberate `zfs rename` |

Before any destructive restore, Shive automatically takes a `shive-prerestore-*` snapshot. If the
target dataset has linked containers, they're stopped and restarted around it - regardless of
whether the schedule itself has Docker awareness enabled.

### Dashboard tile and notifications

The tile on the main dashboard shows, per schedule, status, the time of the last run, the state of
each send target, and an overall traffic light (OK / warning / error). It refreshes every
30 seconds without a page reload.

Notifications go through Unraid's own system - **one per run**, never one per dataset or
container:

| Case | Level |
|---|---|
| Run succeeded | normal (can be turned off) |
| Send skipped, pruning failed | warning |
| Snapshot failed | alert |
| **Container failed to restart** | alert, with its own event name for filtering |

---

## How a run works

```
PRECHECK → QUIESCE → SNAPSHOT → RESUME → SEND LOCAL → SEND REMOTE → PRUNE → DONE
              │          │          │           │             │
              └──────────┴──────────┴───────────┴─────────────┴──► FINALIZE (always)
```

- **Precheck**: array started, pools present and healthy, remote reachable. If a target is
  unavailable, only its send is skipped - the snapshot happens regardless, and the run ends as a
  warning.
- **Quiesce** records that containers were stopped before the first `docker stop`. If the run
  aborts after that point, the safety net knows containers need to come back up.
- **Resume** happens right after the snapshot.
- **Prune** only runs after resume, and only on a successful snapshot - a pruning failure can
  therefore never leave a container down.
- **Finalize** is anchored on a `trap` and always runs: bring containers back up, write status,
  send one notification.

---

## Safety nets

| Situation | Response |
|---|---|
| Script crashes, is killed, power loss | `shive-recover` finds runs with no live process whose containers are still stopped, starts them, and reports "recovered". Runs on Docker start, before every job, on every visit to the Shive page, and on install. |
| Array is stopped | Running jobs receive SIGTERM while Docker is still up - the `trap` brings containers back before Docker itself shuts down. |
| Two runs of the same schedule | `flock` per schedule; the second one is skipped. |
| Two sends to the same target | its own lock per target dataset. |
| Target snapshot already exists | the send is skipped as "nothing to do". |
| Target has a snapshot newer than the base | a clear error instead of a rejected stream. |
| Missed runs (server was off) | optional catch-up on Docker start, relative to the schedule's own age, never to an assumed-infinite one. |
| Restore clones on pool export | removed automatically on unmount. |

---

## Command line

```bash
shive-run <id|name> [--dry-run] [--force] [--no-prune]
          [--send-only local|remote]      # no snapshot: send the newest existing one
          [--no-send | --no-send-local | --no-send-remote]

shive-send --sched <id> --from pool/ds@snap \
           --to local:pool2/ds | ssh://user@host:22/pool/ds [--recursive] [--dry-run]

shive-prune --sched <id> --location source|local|remote --dataset <ds> \
            [--target ssh://…] [--recursive] [--dry-run] [--json]

shive-snapshot flag|unflag|destroy --snapshot <ds@snap> [--target …] [--recursive] [--yes] [--dry-run]
shive-restore stage|unstage|file|dataset|dr …          (see the script's own header)
shive-recover [--quiet]
shive-discover [--refresh]
```

`--dry-run` logs every command that would change something, without running it.

---

## Files and locations

| Location | Content |
|---|---|
| `/boot/config/plugins/shive/shive.cfg` | global settings |
| `/boot/config/plugins/shive/schedules/<id>.json` | one file per schedule |
| `/boot/config/plugins/shive/mappings.json` | manual container overrides |
| `/boot/config/plugins/shive/state/<id>*.last.json` | compact status of the last run |
| `/boot/config/plugins/shive/used_ids` | every ID ever assigned (never reused) |
| `/boot/config/plugins/shive/shive.cron` | generated cron lines |
| `/var/local/shive/` | runtime state, locks, discovery cache (volatile) |
| `/var/log/shive/<id>/` | per-run logs (volatile, path configurable), capped at the newest 200 per schedule |

Everything durable lives on the USB flash drive and survives reboots; runtime clutter deliberately
lives in RAM, both to keep it out of the way and to keep writes to the flash drive low. Retired
run-state files are capped at the newest 50 for the same reason - both caps are enforced by
`shive-recover`, which already runs regularly.

---

## First-time setup – recommended path

1. **Create scratch objects** instead of aiming straight at `appdata`:
   ```bash
   zfs create pin/shivetest && zfs create pin/shivetest/child
   docker run -d --name shivetest -v /mnt/pin/shivetest/child:/data alpine \
     sh -c 'while true; do date >> /data/log; sleep 2; done'
   ```
2. **Create a schedule**, enable Docker awareness, set a local target.
3. **Run a dry run** and read the log. The "linked containers" line names exactly the containers
   that would be stopped for real.
4. **Run for real**, then check: snapshots exist on source and target, a bookmark was created,
   the container is running again.
5. **Run a second time** - the log must show "incremental base".
6. **Practice failure paths**: export the target pool (the run must end as a warning, the
   snapshot must still be taken), kill a job with `kill -9` and call `shive-recover`.
7. **Test a restore**: bring back a file as a copy, then as an overwrite.
8. **Look at Prune preview** and check it matches your own expectation.
9. Only then create the real schedule and enable the remote target.

More detail in [`docs/TESTING.md`](docs/TESTING.md).

---

## Known limitations

- **Docker Compose stacks** are stopped and started container by container, not in the stack's
  dependency order.
- **Bind mounts through `/mnt/user`** can only be resolved if the same path exists on a pool.
  Otherwise, the manual entry in the Containers tab helps.
- **Multiple source datasets in one schedule** land as `<target>/<last-path-component>` - those
  last path components must be unique.
- **The schedule name is a pure label** - storage is keyed by the ID (`<id>.json`). Two schedules
  may share a name; they're told apart by ID.
- **The remote host** needs key-based SSH login without a password prompt, plus `zfs`, `find` and
  `rsync` on its PATH. Setting up the key happens outside the plugin.

---

## Requirements

Unraid 7.2 or newer (responsive WebGUI structure, ZFS 2.3). Binaries used: `zfs`, `zpool`, `jq`,
`docker`, `php`, `rsync`, `flock`, `findmnt` - all included with Unraid.

---

## Repository layout

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
  scripts/shive-snapshot        manual per-snapshot flag/unflag/destroy
  scripts/shive-restore         stage / file / dataset / DR restore
  scripts/shive-recover         crash safety net, clone + log/state housekeeping
  scripts/lib/{common,guard-none,guard-docker}.sh
  event/{started,docker_started,stopping_svcs,unmounting_disks}   Unraid array lifecycle hooks
docs/ARCHITECTURE.md            design, decisions and every bug found along the way
docs/TESTING.md                 how to test on a live box without risking data
test/                           stateful zfs/docker/ssh simulator + the 109-check regression suite
icons/                          logo sources (colour = plugin manager/README, line-art = Settings page + dashboard tile)
```

---

## Releasing

The package MD5 lives in `shive.plg`, because that is the file users install from. The release
workflow builds the package itself and is authoritative for that MD5 - it corrects `shive.plg` on
`main` automatically if the committed value doesn't match its own build, rather than requiring it
to already match. (An earlier version of this workflow required an exact match up front and failed
otherwise; that assumed identical machines produce byte-identical *compressed* packages, which
isn't true in practice - different xz/liblzma builds, e.g. macOS's vs. the CI runner's, can encode
identical input differently.)

So releasing is just:

```bash
git tag 2026.09.24 && git push origin 2026.09.24
```

Running `./build.sh 2026.09.24` locally first is optional - only useful if you want a package to
test with before tagging. Its own computed MD5 does not need to match what CI produces.

If the MD5 needed correcting, the workflow pushes a commit "Set package MD5 for <tag> [skip ci]"
to `main`. Run `git pull` after every release, or your next push is rejected as non-fast-forward.

## Third-party assets

The logo is the "Cask" icon by [justicon](https://www.flaticon.com/authors/justicon) from
[Flaticon](https://www.flaticon.com/free-icon/cask_2617498), used under their Free License
(attribution required) - see [NOTICE.md](NOTICE.md).

## Contributing / running the tests

`bash test/run.sh` runs 109 checks against a stateful ZFS/docker/ssh simulator - no Unraid and no
real pools required. It needs `php-cli`, `jq`, `rsync` and `python3`, and should be run as root in
a throwaway container, since it writes under `/boot`, `/var` and `/mnt`. CI runs it on every push.
