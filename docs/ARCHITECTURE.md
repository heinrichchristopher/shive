# Shive – Architecture

## Job state machine (`shive-run`)

```
INIT → PRECHECK → QUIESCE → SNAPSHOT → RESUME → SEND_LOCAL → SEND_REMOTE → PRUNE → DONE
                    │           │          │          │            │
                    └───────────┴──────────┴──────────┴────────────┴──► FINALIZE (trap EXIT)
```

* State file `/var/local/shive/state/<schedule>.json` is rewritten after every phase.
* `guard_quiesce` sets `quiesced=true, resumed=false` **before** the first `docker stop`.
* FINALIZE always calls `guard_resume`; resume failures are their own alert class
  (`Shive: container resume FAILED`).
* Kill -9 / OOM / reboot: `shive-recover` finds state files with a dead PID and
  `quiesced && !resumed`, restarts the containers, writes a `crashed` last-status.
  It runs at `docker_started` (daemon must be up; at `started` only when Docker is disabled), plugin install, before every job and on every Shive page view.
* PRUNE runs after RESUME and only if SNAPSHOT succeeded – pruning can never keep a container down.
* Locks: `flock` per schedule (skip if running) and per target dataset (serialize receives).

## Independent send schedules

Each target location can run on its own cron line instead of straight after the snapshot
(`own_schedule` per location). The snapshot job then gets `--no-send-<loc>`, and a second line runs
`shive-run <name> --send-only <loc>`: no snapshot, no container quiesce, it picks the newest existing
`shive-<hex>-*` snapshot and sends it — `-I` carries every intermediate snapshot since the target's
last one, so a weekly remote sync of daily snapshots transfers all seven. Send-only runs use their own
flock (`send-<loc>-<name>`) and their own status file (`<name>.send-<loc>.last.json`), so they can run
while a snapshot job is active; the dashboard tile merges both.

## Guard hooks

The runner knows three hooks: `guard_prepare / guard_quiesce / guard_resume`.
`guard-none.sh` (default) is three no-ops, `guard-docker.sh` is the container logic.
The core never mentions Docker; new guards (libvirt, user command) are additional files.

## Container → dataset discovery (`include/docker.php`)

`docker inspect` bind-mount sources → `/mnt/user/<x>` rewritten to `/mnt/<pool>/<x>` for each
imported pool → `findmnt -no SOURCE,FSTYPE -T <path>` → dataset name when FSTYPE=zfs.
Cached 5 min in `/var/local/shive/discovery.json`; `mappings.json` overrides
(`{"name":{"datasets":[…]}}` or `{"name":{"ignore":true}}`).
A container is linked when any of its datasets equals a schedule target or (recursive) lies below it.

## Replication (`shive-send`)

One code path for local and ssh targets: `t_exec` runs a command on the target, the receive
side of the pipe is either local `zfs receive` or `ssh host zfs receive`.
Base selection order: resume token → newest shared `shive-<hex>-*` snapshot (`-I`, intermediates
included) → source bookmark `#shive-<hex>-bm-<targetid>` (`-i`, bookmarks cannot do `-I`) →
full send if the target is absent → refuse if the target exists but shares nothing.
Receive flags: `-u -s -x mountpoint -x canmount -x sharenfs -x sharesmb` (configurable);
`-R` when the schedule is recursive, `-w` (raw) when the source is encrypted.
After success the bookmark is refreshed. No holds are used – the newest snapshot is protected by
the retention rule itself, and the bookmark survives source pruning.

## Retention (`include/retention.php`)

Tags are `shive-<hex>-DDMMYYYY-HHMM` (hex = random, never-reused 6-hex-digit schedule id).
Age from the ZFS `creation` epoch, buckets computed in the server timezone with
`DateTimeImmutable` (DST/rename safe). GFS: newest-first walk; the first snapshot seen in an
hour / day / ISO-week / month bucket claims it while the tier has quota. Tiers count buckets that
contain snapshots, not elapsed time - so `hourly` (default 0) only makes sense on hourly or more
frequent schedules; on a daily schedule "24 hourly" would keep 24 daily snapshots. The newest
snapshot is always kept. `shive-prerestore-*` snapshots have their own small retention (`PRERESTORE_KEEP`).

## Restore (`shive-restore`)

* Browse: mounted datasets straight via `<mountpoint>/.zfs/snapshot/<name>/` (no clone);
  unmounted ones (backup targets) via a read-only clone `<pool>/shive-restore/<id>` with TTL.
* File/folder: `rsync -aHAX --numeric-ids`; default "restore as copy"
  (`<dest>.shive-restore-<ts>`), overwrite needs explicit confirmation.
* Dataset: default rsync copy-back per dataset in the tree (child mountpoints excluded),
  advanced `zfs rollback -r`. Always a `shive-prerestore-*` snapshot first, linked containers
  quiesced/resumed with the same state-file safety.
* DR: `zfs send` from a backup target into a **new** dataset; never `receive -F` onto live data.

## Decisions (confirmed)

D1 findmnt · D2 resume right after snapshot · D3 received snapshots are the target history
(no extra snapshot), `-I` keeps it gap-free · D4 bookmark only, no holds · D11 cron/state/locks/
logs keyed by the schedule's random hex id, not its (renamable) name ·
D5 `.zfs/snapshot` direct + clone for unmounted · D6 rsync default, rollback advanced ·
D7 bash orchestration + PHP decision logic · D8 `-R` with property exclusion ·
D9 logs in RAM + compact last-status on flash · D10 Unraid 7.2+ only.

## Unraid plugin-manager icon (verified on 7.3.2, ShowPlugins.php l.74-93)

`<PLUGIN icon="…">` must be a bare `*.png` file name; the manager resolves it as
`plugins/<name>/images/<icon>` then `plugins/<name>/<icon>`. URLs and paths are silently
replaced by the default plugin-manager icon. Non-`.png` values are Font Awesome names.

## Fixed bugs (log for future-me)

* **shive-prune, empty snapshot set → spurious "prune failed" warning.** The
  classification pipeline runs under `set -o pipefail`; `grep` exits 1 on zero
  matches (a normal case: fresh dataset, nothing of this schedule's yet). That
  tripped `pipefail` and the whole `$(...) || die` fired even though the PHP
  stage after it would have returned a perfectly valid empty plan. Fixed by
  wrapping that grep stage as `(grep "^${PREFIX}" || true)`. Any future pipe
  stage that legitimately expects zero matches needs the same treatment.

## Renaming a schedule

`op=schedule_rename` (old, new) moves the schedule's JSON file, its compact
`state/<name>*.last.json` status file(s) and its `LOG_DIR/<name>` log directory to the
new name, then regenerates cron. Refused if the new name collides or a run for the old
name is currently active. The immutable numeric id (and therefore every existing
snapshot's tag) is untouched - only the display name and everything keyed by it move.
Not migrated (low-value, self-cleaning): stale `/var/local/shive/locks/*<old>*.lock`
files (unused after rename, ignored on next run) and any in-flight `.done.json` debug
state from a just-finished run.

* **Assets missing on every tab except Schedules → dead buttons everywhere else.**
  css+js were only `<link>`/`<script>`-included on `ShiveSchedules.page`, on the wrong
  assumption that Unraid tabs share one DOM. Each `ShiveXxx.page` is actually a
  separate full page load, so `Shive.*` was undefined on Containers/Browser/Log/
  Settings and every `onclick="Shive...."` threw silently. Fixed by moving assets +
  CSRF token + the recover safety-net call into `include/head.php`, required once
  near the top of every tab page's body.

* **Schedule name charset widened; a real injection gap surfaced while doing it.**
  Was `[a-z0-9-]` only. Widened to letters (any case)/digits/space/`_.()-`, 64 chars,
  no leading/trailing space, no `..` - still excludes `/ \\` and the FAT32-forbidden
  `: * ? " < > |` (`/boot` is a FAT32 flash drive; names are matched case-insensitively
  there, same as any other duplicate-name collision). Also dropped a leftover
  `strtolower()` from the old regex era that would have silently discarded case.
  Widening the charset exposed that `shive_cron_write()` interpolated the name into
  the generated crontab line **unquoted** - harmless under the old regex (no shell
  metacharacters could occur) but a real command-injection path once the charset
  opened up. Fixed with `escapeshellarg()` on both cron-line call sites. Every other
  place the name reaches a shell (the `scripts/shive-*` scripts, `api.php`'s
  `script()` helper) was already quoting it correctly.

## Schedule identity: random hex id vs. display name

Every schedule gets a random 6-hex-digit id on creation (`shive_new_id()`), checked against
`/boot/config/plugins/shive/used_ids` (an append-only log of every id ever handed out, not just
currently-active ones) so an id is never reused even after its schedule is deleted - the same
guarantee the earlier sequential counter gave, without needing a monotonic counter. `used_ids`
only grows; at realistic homelab scale (tens to low hundreds of schedules over the plugin's
lifetime) it stays a few KB.

This id is now the *only* thing cron, state files (`state/<id>[.send-local|.send-remote].json`),
locks and log directories (`LOG_DIR/<id>/`) are keyed by - the display name never reaches any of
them. Consequences:
- Renaming a schedule (`shive_schedule_rename()`) now only moves its own `schedules/<name>.json`
  file; state/log history needs no migration because it was never keyed by name.
- The name validation (`shive_valid_name()`) no longer needs to be shell-metacharacter-safe for
  cron's sake - it only has to stay filesystem-safe (no `/ \\`, no FAT32-forbidden chars, no
  `..`) since it's still a file path component for the schedule's own JSON file.
- `cli.php schedule-get`/`linked` accept either the id or the name (`shive_schedule_resolve()`):
  cron and the manual "Run"/"Dry run"/"Prune preview" GUI actions all pass the id; the rest of the
  GUI's CRUD (`schedule`, `schedule_save`, `schedule_delete`, `schedule_rename`) still addresses by
  name, which stays the natural key for user-facing lookups.
- Log/notification text uses the schedule's *name* (resolved from `$SCHED_JSON` early in
  `shive-run`, or looked up fresh in `shive-recover` since a crashed run's state only has the id) -
  a human never sees a bare hex id in a notification subject line.
- Migration: a schedule loaded with anything other than a valid 6-hex id (including the old
  sequential-integer scheme) gets a fresh random id assigned on next load, same one-time-rewrite
  mechanism as the original empty-id migration.

* **Snapshot tag: dropped the literal "id" — `shive-<hex>-DDMMYYYY-HHMM`, not
  `shive-id<hex>-...`.** Changed in `shive_tag_prefix()`, `shive-run`, `shive-send`
  (prefix and bookmark name). Surfaced a second, independent bug while touching this:
  `zfs.php`'s snapshot-to-schedule-name parser used `/^shive-id(\d+)-/` - digits only -
  which silently failed to resolve any hex id containing a letter (i.e. most of them).
  Fixed to `/^shive-([0-9a-f]{6})-/`.

## Audit 2026-09-08 (full code review + simulator run) - fixed

Severity: **crit** = wrong result / data-affecting in normal operation, **high** = feature broken,
**med** = wrong status/UX, **low** = robustness.

| # | Sev | Where | Bug | Fix |
|---|---|---|---|---|
| 15 | crit | shive-run finalize | state file was `mv`'d to `.done.json` and then read for the notification; empty reads made `"" != 0` true -> a bogus **"container resume FAILED" alert on every real run** (invisible in dry-runs, which skip the mv) | read all notification data first, then persist + retire; `mkdir -p state/` |
| 11 | crit | shive-send | target received without `readonly` -> after a reboot the target mounts, browsing updates atime, next incremental receive fails "destination has been modified" | `zfs receive -o readonly=on` |
| 13 | crit | shive-send | bookmark fallback used `-R -i #bookmark`, which ZFS does not support for replication streams -> fallback always failed for recursive schedules | bookmarks on every dataset of the tree; fallback sends per dataset; new children get a full send |
| 20 | high | shive-send | snapshot already present on target (send-only re-run, retry) went down the bookmark path and would fail with "destination already has snapshot" | short-circuit: "target already has @snap - nothing to send" |
| 19 | high | api.php stage | shive-restore printed pretty JSON, api parsed only the last line (`}`) -> **GUI Browse button never worked** | `jq -c` + parse last `{` line |
| 18 | high | zfs.php | `(int)$id` leftover: hex ids resolved to wrong key -> browser showed "(deleted)" for live schedules | string keys |
| 3 | high | shive-restore dr | refused target `local` -> DR receive from a local backup pool impossible from GUI | map `local` to `local:<ds>`; GUI offers DR for local too |
| 5 | high | cli.php catchup | looked up status by name (files are id-keyed) -> every enabled schedule re-ran at every docker_started | by id |
| 6 | med | api.php history | same name/id mismatch -> History tab "never ran" forever | by id |
| 12 | med | shive-send | `add_warning` needs a state file the script never has -> jq error noise on stderr | `log` |
| 4 | med | config.php | `max('error','warning')` compares strings -> an overdue *failed* schedule was downgraded to warning | rank compare |
| 16 | med | shive-restore dataset | `rsync --delete` copy-back tries to delete `.zfs` when `snapdir=visible` -> exit 23 -> restore reported FAILED though data was restored | `--exclude /.zfs/` |
| 17 | med | browser | child datasets appear as *empty dirs* inside the parent's snapshot; restoring one restores nothing | api flags `child_dataset`, GUI links to the child's own snapshots |
| 9 | med | api.php browse | path check rejected snapshots of a pool-root dataset (`/mnt/kilderkin/.zfs/...`) - exactly the user's schedule | regex |
| 8 | med | api.php test_remote | `dirname('tank')` = `.` -> test of a top-level dataset always FAILED | test the pool |
| 14 | med | shive-send | its log lines never reached the run log (`LOG_FILE` not exported) -> failure reasons only in syslog | `export LOG_FILE` |
| 1 | low | shive-run | send-only "nothing to do" exit happened after state_init but before the trap -> recover later reported a phantom "crashed" run | decide before state exists |
| 2 | low | shive-recover | no lock; runs from every job start and every page view could race on the same dead state file | `flock` |
| 10 | low | docker.php | `'\t'` in a PHP single-quoted string reaches Go's template engine literally (docker-version-dependent); docker daemon down fed error text into `docker inspect` as names | `\|SHIVE\|` separator; rc check |
| 7 | low | api.php logs | listed the raw id as schedule | label with display name |

Verified in the simulator (stateful fake zfs/zpool/docker/findmnt/ssh, real rsync): 3-run
snapshot+local-replication cycle with GFS pruning on both sides, bookmark refresh, docker
quiesce/resume ordering, kill -9 mid-QUIESCE + recover, send-only remote through ssh with the
real quoting chain, per-dataset bookmark fallback incl. new child, no-op re-send, target-pool
missing -> skipped/warning, disabled schedule, file restore copy/overwrite, dataset restore
rsync/rollback with pre-restore snapshot retention, DR receive + refusal on existing dataset,
stage/unstage clone lifecycle, every api.php op incl. CSRF rejection, catch-up, tile render.

## Schedule files are keyed by id, not by name (2026-09-10)

`schedules/<id>.json` instead of `schedules/<name>.json`. The display name is therefore never a
path component, never reaches a shell (cron already referenced ids) and needs no uniqueness -
so `shive_valid_name()` now allows any printable UTF-8 up to 64 characters, rejecting only
control characters, invalid UTF-8 and blank/oversized input. Slashes, umlauts, quotes and `&`
are all fine; every output path escapes at display time (`esc()` in JS, JSON encoding in the API),
and the table's inline `onclick` handlers pass the hex id rather than the name, so a name
containing a quote cannot break the markup.

Consequences: renaming is a plain field change (`shive_schedule_rename()` no longer moves files,
and the GUI's save path dropped its separate rename pre-call); `shive_schedule_load()`,
`_delete()`, and the api ops `schedule`, `schedule_delete`, `schedule_rename`, `linked`, `run`,
`prune_preview` all take an id; `shive_schedule_resolve()` still accepts a name for CLI
convenience. `shive_migrate_schedule_files()` rewrites any pre-existing `<name>.json` (with or
without an id inside) to `<id>.json` on first load. `shive_valid_name()` falls back to `strlen()`
when mbstring is absent.

## Cron did not fire (2026-09-10) - two independent causes

**1. update_cron never saw our file.** `update_cron` does not scan `/boot/config/plugins/` blindly;
it derives the directories to search from the symlinks in `/var/log/plugins` (Limetech's safe-mode
fix). A plugin installed from a `.plg` whose stem differs from the config directory - e.g. a locally
built `shive-local.plg` against `/boot/config/plugins/shive/` - makes update_cron look in
`/boot/config/plugins/shive-local/`, so `shive.cron` was never merged into `/etc/cron.d/root` and no
scheduled run ever fired. `shive_cron_install()` now writes the canonical copy, calls update_cron,
**verifies** the entry reached `/etc/cron.d/root`, and only if it did not falls back to
`/boot/config/plugins/dynamix/shive.cron` (dynamix is always installed). The fallback is removed
again whenever it is not needed, and by the .plg's remove step. `op=schedules` returns `cron_active`
so the Schedules tab shows a red warning instead of failing silently.

**2. Minimal cron PATH.** cron runs with roughly `/usr/bin:/bin`, but `zfs`, `zpool` and `flock`
live in `/usr/sbin` and `/sbin` - so even with a correct cron entry every scheduled run would have
died in PRECHECK ("source dataset does not exist"), while the same command worked by hand. common.sh
now exports a full PATH.

## Important snapshots and manual deletion (2026-09-11)

**Important flag.** Stored as the ZFS user property `shive:important=1` **on the snapshot itself**,
not in a plugin-side list: it survives a rename, is visible to `zfs get`, travels with a `zfs send -R`
replication stream, and cannot desynchronise from reality. `retention_plan()` sets flagged snapshots
aside *before* running the policy, so they are neither deleted nor do they consume a GFS tier slot -
flagging an old snapshot therefore does not silently shorten the retained history. Both `age` and
`gfs` mode honour it; `shive-prune` asks `zfs list -o name,creation,shive:important` and passes the
third column through `cli.php retention`.

**Propagation is explicit, not via zfs send.** Relying on the stream to carry the property fails in
both common cases: a non-recursive send omits properties entirely (no `-p`), and - more importantly -
a flag set *after* a snapshot was already replicated is never re-sent, which is precisely the normal
workflow (you mark a snapshot because you realise afterwards that the state mattered). So
`shive-snapshot flag/unflag` resolves every copy of the snapshot via
`cli.php locations <ds> <snap>` (source + configured local/remote counterparts, child paths mapped
through for recursive schedules) and writes the property to all of them, in whichever direction the
user clicked - flagging a target copy propagates back to the source. Unreachable locations are
logged and reported in a notification rather than failing the operation.

`shive-send` then reconciles on every run (`sync_flags`): for all snapshots the two sides share, the
source's flag is written to the target, cleared where the source cleared it. Source is authoritative,
and a snapshot that no longer exists on the source is left alone. That closes the gap for any target
that was offline at flag time, at the cost of one extra `zfs list` per dataset per run.

**Manual deletion.** `scripts/shive-snapshot destroy` requires `--yes`, supports `--dry-run` and
`--recursive` (same-named snapshot on every child dataset), and refuses up front when the snapshot
still has clones - which is the common case while a browse view is open - with a message naming the
clone instead of a raw ZFS error. The api ops `snapshot_flag` / `snapshot_delete` are POST-only and
CSRF-checked like everything else; deletion additionally requires an explicit `confirm`.

Not guarded: `zfs rollback -r` during a full-dataset restore destroys every snapshot newer than the
rollback target, important ones included. That path already demands a typed RESTORE confirmation and
warns about destroying newer snapshots.

## QC pass 2026-09-11 - fixed

| Sev | Where | Bug | Fix |
|---|---|---|---|
| high | common.sh `snap_name` | same-minute collision checked on the parent only; a child still carrying the name (non-recursive manual delete) made `zfs snapshot -r` fail | tree-wide check for recursive schedules |
| high | shive-prune | child snapshots whose parent-level twin is gone were never classified again -> unbounded leak | orphan sweep on recursive prune (important ones excluded) |
| med | shive-run | a manual "Run" sent to targets that have their own send schedule (only cron passed `--no-send-<loc>`) | `own_schedule` honoured inside the runner |
| med | shive-send | flag reconciliation skipped on the "target already has @snap" path -> an offline-at-flag-time target that was already up to date never caught up | `sync_flags` runs on the no-op path too (bookmark refresh does not) |
| med | config.php save | a caller-supplied id was adopted if merely well-formed -> could enter the system bypassing `used_ids` | only ids of existing schedule files are accepted |
| med | config.php save/load | `array_replace_recursive` merges lists by index: a 1-element `exclude_props` kept the other three defaults | list fields taken verbatim |
| low | common.sh | PATH was *prepended* with system dirs, shadowing a caller's PATH | appended |
| low | docs | install instructions still said `shive-local.plg` | fixed; explained why the name matters |

Verified: `test/run.sh`, 76 checks, all green.

## Missing target parent: explicit create, not implicit (2026-09-11)

The local target field was a strict `<select>` populated only from existing datasets - a target
that does not exist yet (the normal case for a fresh backup destination) could not be entered at
all, forcing users to pre-create it by hand, which is exactly how the earlier "target exists but
shares nothing" trap was triggered in testing. Changed to free text with a `<datalist>` for
autocomplete (matches how the remote field already worked).

Auto-creation is deliberately **not** silent/automatic on every scheduled run - that would hide
typos (an accidental new empty dataset tree instead of a loud warning) on a pool the plugin does
not own. Instead: `op=target_parent_status` (GET) checks on blur whether the *parent* of the typed
path exists (for local or, via the same ssh spec `testRemote()` builds, for remote); if not, an
inline "create it now" link calls `op=target_parent_create` (POST, `zfs create -p <parent>`
locally or via `zfs_exec()` over ssh). Only ever creates the parent chain, never the leaf target
itself - the leaf continues to be created naturally by the first real `zfs receive`, unchanged
from what was already tested working. Same-turn fix: remote never had the "parent missing" runtime
guard local already had in `shive-run` PRECHECK (reachability and pool health were checked, not
the specific path) - a missing remote parent failed the whole job with a raw ZFS error instead of
a graceful per-location skip+warning. Now symmetric with local.

## Container discovery: findmnt bind-of-subdirectory bracket notation (2026-09-11)

Confirmed on Docker 29 / real findmnt output: for a bind mount of a *subdirectory* of a ZFS
dataset (not the dataset's own root mountpoint - e.g. Unraid binding /var/lib/docker from
`<pool>/system/docker`), `findmnt -o SOURCE` reports `dataset[/subpath]`, not a bare dataset
name. That whole string was being taken as the dataset name verbatim - `pin/system[/docker]`,
which is not a valid ZFS dataset name and would never match anything. Fixed by stripping
everything from `[` onward before returning it. Does not affect the common case (bind mount of
a path that sits directly inside an already-mounted dataset, no separate bind-mount of a
subdirectory involved) - verified those still resolve unchanged.

## Critical: SIGTERM deferred behind a blocking docker stop (2026-09-11, found via manual array-stop test)

`guard_quiesce` ran `docker stop -t $TIMEOUT "$c"` as a plain foreground command. Bash defers a
trapped signal until the current foreground command returns - so a container that ignores
SIGTERM (`docker stop` then blocks for its full timeout) would swallow `stopping_svcs`' own
SIGTERM for that entire duration. `stopping_svcs` itself only waits up to 60s before escalating
to SIGKILL, which cannot be trapped. If `DOCKER_STOP_TIMEOUT` is close to or exceeds that 60s
window (the field case: 61s, deliberately or not), the SIGKILL race can win - the resume trap
never runs, and the container is left down after an array stop. Reproduced directly on the live
box: SIGTERM sent 1.5s into an 8s-blocking stop, script did not react for the full ~8-9s.

Fixed by running `docker stop` in the background and `wait`-ing on it instead: unlike a
synchronous foreground command, `wait` on a background job is documented to return immediately
when a trapped signal arrives, with the trap firing right after. Same test after the fix: exit
in ~1s instead of ~9s, container resumed. Added as a permanent check in `test/run.sh`
("SIGTERM during a blocking docker stop") using the simulator's new per-container `stop_delay`.

`guard_resume`'s `docker start` calls remain plain foreground commands - deliberately not
hardened the same way: they run only from inside the trap handler itself (once signal handling
is already in progress), a much narrower and harder-to-fully-close window, and resume is
normally fast. Not chased further; the quiesce-phase case was the one with a real, reproduced
field impact.

## Target fields are always a root now (2026-09-11)

Previously `target_for()` special-cased a single-dataset schedule: the configured
`local_target`/`remote_target` `.dataset` value was used as the literal destination, only
getting `/<basename>` appended when a schedule had *multiple* source datasets. That meant the
field's meaning was inconsistent (literal path vs. root) depending on schedule shape, and a
single-dataset schedule could not share a target root with another schedule without a manual
full-path workaround - exactly the trap that caused the early "shares no snapshot/bookmark"
confusion during testing (pre-creating what was meant to be a literal destination).

`target_for()` is now unconditional: `echo "$1/${2##*/}"`, always root+basename, regardless of
dataset count. PRECHECK's existence check moved with it - it now checks the configured value
itself (the root), not its dirname, since `zfs receive` creates exactly one new leaf and only
ever needs its immediate parent (the root) to already exist. `target_parent_status`/
`target_parent_create` (api.php) simplified the same way: no more dirname math, they check/create
the passed dataset directly. `zfs_target_parent()` (zfs.php) is gone, no longer needed.

This is a breaking semantic change for any schedule that had a target configured under the old
rules with a multi-level path meant as a literal destination - review existing schedules'
local/remote target values after upgrading. No production schedule existed yet when this changed.

## QC pass 2026-09-11 (second) - the root refactor left four call sites behind

Changing `target_for()` to always append the basename (previous entry) turned out to be an
incomplete refactor: the same `<root>/<basename>` rule was open-coded in four other places, each
still carrying the old "single source dataset -> use the configured value literally" special
case. None of them would have failed loudly - they'd silently point at the wrong dataset.

| Sev | Where | Effect |
|---|---|---|
| high | `shive_snapshot_locations()` (config.php) | the important-flag star would set/clear the property on the wrong target dataset (or find nothing) for any single-dataset schedule - i.e. the common case |
| med | `prune_preview` (api.php) | preview showed keep/destroy for a dataset the real prune never touches |
| med | `fillDs()` (shive.js) | snapshot browser offered only the remote *root* for browsing, which by definition holds no snapshots - remote backups were unreachable from the GUI |
| low | `test_remote` (api.php) | probed `dirname(root)` instead of the root; wrong thing reported as reachable |

Fixed by introducing **one** PHP-side definition, `shive_target_for($root, $source)` in
config.php, and routing every PHP call site through it; `fillDs()` derives its list from the
schedules it already has. The shell side keeps its own one-liner `target_for()` in shive-run
(crossing the PHP/shell boundary for this would cost a subprocess per dataset per run) - the two
are documented as a matched pair in both files. `test/run.sh` now pins the rule from both sides
("root semantics are consistent everywhere"), so a future divergence fails the suite instead of
silently mis-targeting.

Also fixed: `br.init()` never populated the shared `schedules` variable (only `sched.load()` did),
so opening the Snapshots tab directly left `fillDs()` without the data it now needs.

## QC pass 2026-09-11 (third) - two product bugs, both about resilience

**1. Orphaned `docker stop` kept the schedule lock (high).** The previous QC round backgrounded
`docker stop` so a trapped SIGTERM wouldn't be deferred. A backgrounded process inherits every
open fd - including fd 9, the schedule's `flock`. So if a run was killed (SIGKILL, OOM, power)
while a stop was in flight, the orphaned child held the lock until it finished; the *next*
scheduled run then hit `another job holds lock` and exited 75 - a backup that silently never
happened and never raised an error. Fixed with `9>&-` on the background command. Proven both
ways in the simulator: without it the orphan is visible in `/proc/<pid>/fd` holding the lock and
the next run is skipped; with it the lock is free immediately and the next run proceeds.

**2. One missing source dataset failed the whole schedule (medium).** PRECHECK treated a
non-existent source dataset (or a source pool that isn't ONLINE) as fatal for the entire run. On
a multi-dataset schedule, deliberately destroying one dataset therefore silently stopped backups
for all the others too. Now: skip it with a warning, keep going with what's left, and fail hard
only when nothing remains (`no source dataset available`). The run reports `warning`, so the
tile and notification still surface it.

Also confirmed by testing the previously untested multi-dataset path end to end: three sources
under one root each get their own `<root>/<basename>` target, the important-flag star resolves to
the correct target only, prune_preview covers all six locations, all three sends go incremental,
and a basename collision is rejected at save time. A destroyed target dataset self-heals via a
full send on the next run.

Test-harness notes from this round: `test/run.sh` grew to 93 checks. Two harness (not product)
defects were fixed while investigating: the crash-recovery section depended on an earlier
section's cleanup of the fake docker state (which the fake's read-modify-write could race), and
the DR check picked the newest *source* snapshot and looked for it on the *target*, where
`--no-send` sections meant it might never have been replicated. Both now set up their own
preconditions. Occasional single-check flakes under sandbox load remain - see test/README.md.

## Catch-up misfired for never-run schedules (2026-09-11, reported live)

`cli.php catchup` used `PHP_INT_MAX` as the "age" of a schedule that has never run, so it always
looked infinitely overdue - any reboot immediately ran every enabled schedule that hadn't fired
yet, regardless of whether its actual scheduled time had passed. A schedule created at 14:00 for
"daily at 00:00" would fire the moment the array started, not at the next midnight.

Fixed with a `created` timestamp (new field, immutable once set, same pattern as `id`): for a
never-run schedule, age is now measured from creation, not from negative infinity. A schedule
created 10 minutes ago no longer looks 24h overdue; one that has genuinely existed, enabled,
for longer than its own interval without ever firing still catches up correctly (verified both
ways). Schedules saved before this field existed get it backfilled from the schedule file's own
mtime on next load - an approximation (their real creation time isn't recoverable), but it stops
the immediate-refire behaviour going forward instead of leaving it broken forever for pre-existing
schedules.

Found while adding the regression test: the "genuinely overdue" case runs a real job in the
background, which (a) sends its own success notification and (b) leaves a real snapshot behind
after the test schedule is deleted (snapshots intentionally outlive their schedule) - both had to
be cleaned up explicitly so the test doesn't leak state into later sections (it was quietly
shifting "which snapshot is oldest" for the important-flag section, which cascaded into five
unrelated-looking failures until traced back).

## QC pass 2026-09-11 (fourth) - cleanup + one validation gap

Focus of this round was removing things rather than adding them.

**Removed as dead code** (verified unreferenced first, not assumed):
- `human_bytes()` in zfs.php - the GUI formats sizes in JS, this was never called.
- `op=schedule` (read one schedule) - nothing read it; the GUI works from `op=schedules`.
- `op=schedule_rename` and `shive_schedule_rename()` - renaming became an ordinary save once the
  schedule id started travelling with the payload; the separate path had no remaining caller.
  Note this supersedes the "Renaming a schedule" section further up: there is no rename op now.
- `cli.php schedule-list` - no caller.

**Deduplicated:** `showLinked()` in the GUI reimplemented the container-matching rule
(dataset equality plus the recursive child-path case) in JavaScript, because the editor has
unsaved state and `op=linked` only accepted a saved schedule id. That is the same
two-implementations-of-one-rule shape that caused the earlier `target_for()` drift, so
`op=linked` now also accepts `datasets` + `recursive` directly and the JS just renders what the
server returns. The rule lives in `docker_linked()` only.

**Deduplicated:** the list of derived fields (`tag_prefix`, `cron_expr`, `spec`, ...) that must
never be persisted was written out twice, in two different orders. Now `shive_strip_derived()`,
used by both persist paths - a future derived field is added in one place.

**Validation gap (the one real finding):** source datasets were strictly validated, but backup
*target* roots, the remote host, the remote user and the port were not validated at all. These
reach an ssh command line, so a value like `h; rm -rf /` as a hostname would have been passed on
to ssh. Not a privilege-escalation issue - only someone who already has Unraid root can configure
a schedule - but inconsistent, and it let plain typos (a space in a dataset path) fail at run time
instead of at save time. All four now get the same shape checks sources already had, with
regression tests covering both the hostile inputs and the legitimate ones that must still pass.

Suite: 104 checks.

## QC pass 5 (2026-09-12) - unbounded growth, flash wear, last duplicated rule

**Unbounded runtime files (medium).** Nothing ever removed run logs
(`LOG_DIR/<id>/*.log`) or retired run state (`/var/local/shive/state/*.done.json`,
`*.recovered.json`). Both live in RAM so a reboot clears them, but within one uptime an hourly
schedule adds 24 of each per day per location - a slow climb on a server that stays up for
months, for files nobody reads after the fact. `shive-recover` (which already runs regularly and
already cleaned expired restore clones) now caps them: newest 200 logs per schedule, newest 50
retired state files. The History tab only ever showed recent runs anyway.

**Flash wear (low).** `shive_cron_write()` rewrote `/boot/config/plugins/shive/shive.cron`
unconditionally, and it runs on every array start and every schedule save - on a USB stick.
Now the write is skipped when the file already contains exactly that content.

Worth recording how the first attempt at this was wrong: the obvious optimisation is to return
early when everything already looks right, including `/etc/cron.d/root` already containing our
line. The suite immediately failed the "cron fallback when installed under another .plg name"
check - because `/etc/cron.d/root` can be *stale* for reasons unrelated to our file's content
(the plugin installed under a different .plg stem changes where `update_cron` even looks), so
trusting it to decide whether to re-verify defeats the fallback that exists precisely for that
case. Corrected to skip only the flash write, never the verification.

**Last duplicated rule.** `shive-prune` derived the snapshot tag prefix from the schedule JSON
(`.tag_prefix`, i.e. from `shive_tag_prefix()` in PHP), but `shive-run` and `shive-send` rebuilt
the same string from a literal. All three now read `.tag_prefix` - each already had the JSON in
hand, so this costs nothing. A regression check asserts no script reconstructs it from a literal
again. As of this round there is no rule left that is implemented in both PHP and shell.

Suite: 109 checks.

## QC pass 6 + release prep (2026-09-12)

**Linter noise cleared so real warnings are visible.** shellcheck reported ~18 findings, all of
them false positives of two kinds: SC2034 for variables that are read by the sourced helpers in
`lib/common.sh` rather than locally, and SC2046 for deliberate word splitting (the
`$( [ cond ] && echo --flag )` idiom for optional arguments, and `recv_cmd`/`t_ssh_cmd` printing a
command line meant to be split and executed). Annotated with targeted `# shellcheck disable`
directives explaining *why* each is intentional. A linter that always prints 18 warnings is a
linter nobody reads.

Worth noting how that went wrong first: one directive was placed in front of an individual `case`
branch, which is invalid - shellcheck reported SC1073/SC1072 *parse errors*, i.e. it could no
longer analyse the file at all. `bash -n` passed it happily, so syntax checking alone would not
have caught it. Moved to precede the whole `case` statement.

**Builds are now byte-reproducible.** `tar` was picking up file mtimes and directory order, so two
builds of identical sources produced different packages and therefore different MD5s. This matters
beyond tidiness: the MD5 that users' Unraid installs verify lives in the committed `shive.plg`, so
a checksum that changes for reasons unrelated to content cannot be verified by anyone, including
CI. Fixed with `tar --sort=name --mtime=@0 --owner=0 --group=0 --numeric-owner`.

A first attempt built the file list by hand (`find -print0 | sort -z | tar --files-from=-
--no-recursion`) and produced an archive with 358 entries for 51 files - the rest emitted as
hardlink records. Checking the entry count, not just "does it build", caught it; `tar`'s own
`--sort=name` does the job correctly.

**Release flow.** The MD5 constraint above means the version must be built and committed *before*
tagging. `release.yml` therefore rebuilds from the tag and diffs against the committed `shive.plg`,
failing the release on mismatch rather than publishing a package whose checksum the committed .plg
rejects. `ci.yml` runs syntax checks, shellcheck at error level, .plg XML validation and the full
109-check suite on every push.

Added for publication: MIT `LICENSE`, `.gitignore` (build output stays out of the repo; packages
are release assets), install URL and badges in both READMEs, and the release procedure documented
in README.md.

## Dataset exclusions (2026-09-14)

Recursive schedules can exclude child datasets, at two levels: `exclude_datasets` on the schedule
(no snapshot at all) and `exclude_datasets` per target (snapshot taken, just not sent there).
Target lists are **additive** - a dataset with no snapshot cannot be replicated regardless, so a
target can only ever subtract further. `shive_schedule_load()` derives `exclude_effective` per
target (schedule-level ∪ target-level) so the shell side never merges the two lists itself.

Implementation notes:
- `zfs snapshot -r` has no exclude option. With exclusions, the tree is enumerated and every
  wanted dataset passed to **one** `zfs snapshot` call - that form is atomic, so the point-in-time
  guarantee `-r` gives is preserved. (Snapshotting them one call at a time would not be.)
- `zfs send -R` likewise cannot skip datasets, so with exclusions each dataset is sent
  individually, parents first (`zfs list` order), reusing the per-dataset base selection that
  already existed for the bookmark-fallback path. **Without** exclusions the tested `-R` path is
  used unchanged - the new code only runs when someone actually configured an exclusion.
- Validation rejects exclusions that aren't below a source, that are a source themselves, or that
  don't exist - an exclusion matching nothing silently excludes nothing, which is the failure mode
  worth catching at save time. The existence check is skipped when ZFS isn't answering, so a
  stopped array can't block saving a valid schedule.

GUI detail: the per-target lists only offer datasets that still have a snapshot - anything already
excluded at the schedule level is dropped from them, since "additionally skip when sending" is not
a choice that exists for a dataset that was never snapshotted. The lists therefore re-render when a
snapshot exclusion is ticked, and `edit()` calls `excludeUI()` a second time after applying a saved
schedule's snapshot exclusions (the first call runs before they are set, so the target lists would
otherwise open unfiltered).

**Bug found while testing this:** picking the snapshot name before knowing which datasets are
involved. `snap_name` only checked the first source dataset's subtree for a same-minute collision,
but `zfs snapshot` fails the *entire batch* if any one name already exists - so a collision on a
second source dataset, or on a tree reshaped by exclusions, failed the run. The name is now chosen
after the dataset list is built and checked against every dataset in it.

## Two bugs in the exclusion feature's dry-run/first-run path (2026-09-15, reported live)

Both surfaced together on a real dry-run of a schedule with a target-level exclusion, against a
target root that existed but whose per-dataset child had never actually been created (nothing had
ever really been sent there yet - a first-time dry-run is exactly this situation for everyone).

**1. `do_send()`'s exclusion-aware per-dataset branch checked whether the real snapshot
`$d@$SNAP` existed to decide "was this excluded from the snapshot" - but a dry-run never creates
that snapshot for real (only logs that it would). Every dataset therefore silently failed that
check and was skipped, so a dry-run of any schedule with exclusions showed literally nothing
under SEND_LOCAL/SEND_REMOTE: no sends, no exclusion log lines, no error - useless for exactly
the thing dry-run exists for (verifying what a schedule would do before running it for real).
Fixed by checking membership in `EXCLUDE_SNAP` (the actual configured exclusion list) directly,
which is correct in both dry-run and real runs; the live snapshot-existence check still runs, but
only outside dry-run, to catch the unrelated (and very narrow) case of a dataset appearing in the
tree after snapshotting but before sending.

**2. `shive-prune`'s classification pipe only neutralized `grep`'s exit code for zero matches
(`grep ... || true`, from an earlier fix) - it did nothing for the *first* stage of the pipe,
`zfs list`, failing outright when the location's dataset doesn't exist at all. Under `pipefail`
that propagates straight to `|| die "retention classification failed"`, a FATAL for a state
(nothing has ever been sent here yet) that is entirely normal - a first-ever dry-run against any
target hits it every time, and so would a real run whose send this time was skipped or failed.
Fixed by checking existence up front and reporting it the same way an existing-but-empty dataset
already is - "nothing to prune yet" - instead of dying.

Both were latent since exclusions were added five days earlier; nobody had dry-run a schedule with
a target-level exclusion against a not-yet-populated target until now. Added as permanent checks;
suite: 136.

## Replication base lost on the TARGET side (2026-09-21)

Question raised before submitting to Community Applications: can retention or a user delete the
common base and break replication? Answer, verified in code and simulator:

- **Source side, automatic or manual:** fully handled. Every successful send refreshes a bookmark
  on the source at the snapshot just sent; bookmarks survive their snapshot's deletion, and
  `shive-send` falls back to them. Verified live earlier on the real box.
- **Target side, Shive's own retention - even set much shorter than the source:** cannot hit it.
  `retention_plan()` always keeps the newest snapshot, and the newest on a target is by
  construction the one the source bookmark points to (it is refreshed on every send).
- **Target side, by hand or by another tool:** was the one real gap. The bookmark path went ahead
  without checking the target, and `zfs receive` then refused with "most recent snapshot of X does
  not match incremental source" - safe (nothing received, nothing diverges) but not actionable.

Now, before any bookmark-based send, `bm_check()` compares by **GUID**: a bookmark carries its
origin snapshot's GUID, and a snapshot keeps its GUID through send/receive. Two distinct outcomes,
each with its own plain-language explanation and recovery step: origin gone from the target (reset
replication for that target), or a foreign snapshot newer than it on the target (destroy that
snapshot). `zfs receive` is not even attempted. The reason is also carried into the recorded error
via `send_failure_reason()`, so it shows up in the notification and History tab, not only in the
log file.

**Second bug found while building the test for this.** The "target already has @snap - nothing to
send" short-circuit compared snapshot **names** only. Names carry only the minute, so once the
source's snapshots are gone, a new snapshot can reuse a name still sitting on the target. Shive
then reported success, sent nothing, and moved the bookmark to a snapshot the target does not have
- a silent failure reported as success. Now also verified by GUID; a same-named but different
snapshot is refused with an explanation. A genuine retry (same GUID) is still "nothing to send".

**Simulator fidelity.** The ZFS simulator had no GUIDs and accepted a bookmark-based incremental
into any target unconditionally - so neither failure could ever have been reproduced by the suite.
It now assigns a GUID per snapshot, preserves it through send/receive, copies it into bookmarks, and
refuses a bookmark receive whose origin isn't the target's newest snapshot, like real ZFS. All
cases are driven through `shive-send` with explicitly named snapshots so none depends on the wall
clock. Suite: 143.

One unexplained single failure of the "foreign newer snapshot" check on the first suite run after
adding it; not reproduced in the next three runs, and the case has no timing dependence. Noted
rather than guessed at.

## Excluding a dataset did not exclude its container from stop/resume (2026-09-24, reported live)

Question from the field: excluding a child dataset from the snapshot (e.g. to temporarily leave a
container out of the backup) - does the container still get stopped and restarted? It did, and
pointlessly: `docker_linked()` matched a container's bind-mount path against the schedule's
`datasets`/`recursive` alone, with no awareness of `exclude_datasets`. A container whose entire
matching dataset was excluded from the snapshot was still identified as linked and still quiesced
around a snapshot that no longer contained any of its data - downtime with no backup benefit.

Fixed: `docker_linked()` takes an `$exclude` list and skips a bind-mount whose dataset is excluded
(or sits below an excluded one, matching the same rule `is_excluded()` uses elsewhere) before
matching it against the schedule's datasets. A container with another, non-excluded mount is still
linked for that mount's sake - only the specific excluded relationship stops counting.

Threaded through every caller: the real run (`cli.php linked`, called by `guard_prepare()` - the
actual code path `shive-run` uses to decide who to stop), the saved-schedule API path, and the
editor's live "Show linked containers" preview (which now sends whatever exclusions are currently
ticked, even before the schedule is saved, so the preview cannot disagree with what a real run
would do).

**Sandbox note, not a code issue:** this session started from a fresh container after the previous
one reset. The repo tarball's exec bits did not survive re-packaging/download - every script under
`scripts/`, `event/`, and every fake binary in `test/` had lost +x, which cascaded into 57 unrelated
-looking test failures (starting from the very first real `shive-run` invocation) until traced back
to `test/zpool` specifically reporting every pool "MISSING". Restored with `chmod +x` throughout;
worth remembering for any future fresh-sandbox start. Suite: 147.

## Exclusion checkboxes looked like normal selection (2026-09-24)

A checked checkbox reading as "excluded" is backwards from how a checkbox normally reads
("checked = included/selected"), and the three exclusion lists used plain native checkboxes -
same blue checkmark as the dataset picker above them that means the opposite thing. Given a
distinct class (`shive-excl`) and restyled: `appearance:none`, plain bordered box at rest, red
fill with a white minus (not a checkmark) when checked. Only the three exclusion lists
(`f-exclude`, `f-local-exclude`, `f-remote-exclude`) go through the render() helper that assigns
this class; the dataset-inclusion picker uses its own `f-ds` class and is untouched.

## Full-code QC pass (2026-09-24)

Every script and PHP file read end to end, not only recent changes. Findings, all fixed with
regression checks (suite 147 -> 173):

**grep -q inside pipelines under pipefail (the important one).** `grep -q` exits at the first
match and closes the pipe; a writer still writing gets SIGPIPE (141) and `pipefail` reports the
whole pipeline as failed although grep matched. Timing-dependent - this was the "unexplained"
intermittent failure of the target-base test seen in two sessions; captured evidence showed the
bookmark GUID present on the target while `bm_check` reported it gone. Six sites. Worst: the orphan
sweep in shive-prune, where a false "no parent twin" deletes a valid child snapshot (in practice
only with very long snapshot lists, but correctness must not depend on pipe-buffer sizes).
Deterministic proof with a >64 KB list: old pattern 0/20, `grep -x ... >/dev/null` 20/20. A static
rule in test/run.sh rejects the pattern from now on; the reason is documented in common.sh.

**guard_resume** kept a failed attempt's `resume_failed` and error after a later retry (from
finalize) succeeded -> "containers still DOWN" for running containers. Cleared on success, recorded
as a warning. `RESUME_RETRY_DELAY` (default 5) makes the retry backoff tunable for tests.

**Restore.** Rollback: `zfs rollback -r` destroys every newer snapshot and bookmark, including the
pre-restore snapshot taken just before it - which the notification and the GUI confirm dialog both
promised as an undo point. Rollback now takes none and says plainly there is no undo. DR: backup
targets carry readonly=on and a -R stream brings it back, so the restored dataset was read-only;
received with `-x readonly`. File-restore dry-run no longer creates the destination directory.
Backup runs and dataset restores exclude each other via `active_runs()` (a run would otherwise
resume its containers in the middle of a restore).

**Delete button** could remove the newest snapshot on a backup target - the base of the next
incremental. Refused with an explanation (the source side is fine, the bookmark covers it). The
"base lost" message no longer claims only other tools can cause this.

**Cross-schedule collisions.** Basename uniqueness was checked within one schedule only; two
schedules sharing a root with `pin/appdata` and `kilderkin/appdata` both wrote `<root>/appdata`.
Rejected at save time, naming the other schedule.

**Settings** are sourced by bash: values were double-quoted with only `"` removed, so `$(...)` and
backticks still expanded, and numeric fields were only limited by browser min/max. Strict per-key
validation server-side, values written single-quoted. Not a privilege boundary (only root-equivalent
admins can save settings), but a correctness one.

**LOG_DIR scope.** Housekeeping deleted old `.log` files in every subfolder of the configurable
LOG_DIR, and the log API listed/read them - pointing LOG_DIR at /var/log would have reached system
logs. Both now only touch `<LOG_DIR>/<6-hex id>/`.

**Smaller:** browse path filter allowed `..` after the snapshot prefix; recovery hints leaked the
internal `t_exec` name and did not say which host to run commands on; a resume token that can never
complete failed every run without mentioning `zfs receive -A`; the old bookmark was dropped even
when there was no new snapshot to bookmark; dry-run skipped the orphan sweep silently;
`state_set` leaked a temp file when jq failed.

## build.sh only worked on Linux (2026-09-24, found when actually releasing from a Mac)

The reproducibility fix from an earlier round used GNU tar's `--sort=name --mtime --owner --group
--numeric-owner`. That is GNU-tar-only syntax - macOS ships bsdtar (libarchive) as `/usr/bin/tar`,
which doesn't recognize `--sort` at all and fails immediately. Since the release flow requires
build.sh to run locally (on whatever machine tags a release) before the GitHub Actions runner
rebuilds and verifies against the committed shive.plg, this made a release impossible from a Mac -
found only when actually trying to release from one, because it had only ever been run in the
Linux sandbox before.

Worse than a portability bug: even patched to bsdtar's own flag names, there's no guarantee two
*different* tar implementations encode "the same" archive to identical bytes - which is exactly
what the committed MD5 depends on. Fixed by removing the platform tar binary from the reproducible
path entirely: an embedded Python script builds the .txz via the standard library's `tarfile`
module (mode `w:xz`), walking the tree in sorted order and zeroing mtime/uid/gid/uname/gname on
each entry. Since the exact same interpreter code runs regardless of OS, Linux and macOS now
produce byte-identical output - verified reproducible (two builds, identical MD5) and faithful
(extracted output diffs identical to the source tree, content and permissions) in this session.

Directory entries are no longer written (only files - tar auto-creates parent directories on
extraction, and the package tree has no genuinely empty directories), so the entry count dropped
from 51 to 37; purely cosmetic; verified against the extracted tree, not against the entry count.
