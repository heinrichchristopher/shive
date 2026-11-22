# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Shive is an Unraid 7.2+ plugin: scheduled ZFS snapshots, age/GFS retention, optional Docker
quiesce/resume, local + SSH replication, and a snapshot browser/restore in the WebGUI. Bash does
the orchestration, PHP holds the decision logic and the GUI backend. `docs/ARCHITECTURE.md` is the
design record and the log of every bug found so far; read the relevant section before changing a
subsystem, and add an entry there when you fix a non-trivial bug.

## Commands

Lint (what CI's `lint` job runs):

```bash
find src \( -name '*.php' -o -name '*.page' \) -print0 | xargs -0 -n1 php -l
P=src/usr/local/emhttp/plugins/shive
for f in $P/scripts/shive-* $P/scripts/lib/*.sh $P/event/*; do bash -n "$f"; done
shellcheck -S error -x $P/scripts/shive-* $P/scripts/lib/*.sh $P/event/*
for f in $P/js/*.js; do node -e "new Function(require('fs').readFileSync('$f','utf8'))"; done
xmllint --noout shive.plg
```

`bash -n` does not catch everything: a misplaced `# shellcheck disable` (e.g. before a single
`case` branch) is a shellcheck parse error, so run shellcheck too.

Regression suite: `bash test/run.sh` runs the plugin against the stateful fakes in `test/`
(`zfs`, `zpool`, `docker`, `findmnt`, `ssh`). It must run **as root in a throwaway Linux container**
(it writes under `/boot`, `/var`, `/mnt`) and **in a copy of the checkout** (it `sed -i`s
`test/docker`). Needs php-cli, jq, rsync, python3, procps (`pkill`). On this Mac:

```bash
podman run --rm -v "$PWD":/src:ro docker.io/library/ubuntu:24.04 bash -c '
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq && apt-get install -y -qq php-cli jq libxml2-utils shellcheck rsync python3 procps >/dev/null
cp -a /src /work && cd /work
PATH=$PWD/test:$PATH bash test/run.sh'
```

There is no per-test selection: `run.sh` is one linear script whose sections (`sec "..."`) build
on shared simulator state; each check prints PASS/FAIL and the exit code is non-zero on any FAIL.
To iterate on one area, temporarily trim the script in the container copy. Known noise: under
Podman on macOS "dataset restore rsync rc=0, .zfs preserved" fails (`rsync -X` can't set selinux
xattrs); wall-clock-dependent checks can flake under load - re-run before assuming a regression.
New features and bug fixes get a permanent check in `run.sh`. `test/apitest.php GET|POST
'op=...&k=v' [nocsrf]` drives `include/api.php` directly.

Build: `./build.sh [YYYY.MM.DD[a|b|c…]]` (same-day re-releases get a letter suffix) needs GNU tools (`md5sum`, GNU sed, xmllint, python3), so on
macOS run it in the pinned `python:3.12-slim@sha256:…` container shown in README.md "Releasing".
It builds a deterministic `build/shive-<ver>-noarch-1.txz`, patches `version`/`pkgMD5` into
`shive.plg`, and writes `build/local/shive.plg` for a `file://` test install. Releases are built
once locally and that exact file is attached to the GitHub release - never rebuild elsewhere (MD5s
differ across liblzma builds). A local test `.plg` must be named `shive.plg` or Unraid's
`update_cron` won't find the cron file.

## Architecture

All shipped code lives under `src/usr/local/emhttp/plugins/shive/` (mirrors the Unraid filesystem).

- **Scripts (`scripts/`)**: `shive-run` is the job runner, a state machine
  `PRECHECK → QUIESCE → SNAPSHOT → RESUME → SEND_LOCAL → SEND_REMOTE → PRUNE → DONE` with FINALIZE
  on `trap EXIT`. It calls `shive-send` (one code path for `local:pool/ds` and
  `ssh://user@host:port/ds`), `shive-prune`, etc. `lib/common.sh` is sourced by all of them.
- **Guards**: `shive-run` only knows `guard_prepare/guard_quiesce/guard_resume`;
  `lib/guard-docker.sh` implements them for containers, `guard-none.sh` is no-ops. The core never
  mentions Docker.
- **PHP (`include/`)**: `config.php` (schedules, validation, cron generation, status), `zfs.php`,
  `docker.php` (container → dataset discovery via `docker inspect` + `findmnt`), `retention.php`
  (pure keep/destroy function). Shell scripts reach PHP logic only through `cli.php <command>`;
  the GUI reaches it through `api.php` (GET = read, POST = state-changing, CSRF-checked).
- **GUI**: each `Shive*.page` tab is a separate full page load and must `require` `include/head.php`
  (assets, CSRF token, `shive-recover` safety net). `js/shive.js` holds all tab logic;
  `ShiveDashboard.page` + `shive-tile.*` is the dashboard tile.
- **Events (`event/`)**: Unraid array lifecycle hooks - `docker_started` runs recover + catch-up,
  `stopping_svcs` SIGTERMs running jobs so the trap restarts containers before Docker stops.

### Invariants that are easy to break

- **Schedule identity is a random, never-reused 6-hex id** (`used_ids`). Schedule files, cron
  lines, state, locks and log dirs are keyed by the id; the display name is free UTF-8 and must
  never reach a path or shell. Snapshot tags are `shive-<hex>-DDMMYYYY-HHMM`; scripts read the
  prefix from the schedule JSON's `.tag_prefix`, never rebuild it from a literal.
- **One rule, one implementation.** Duplicated PHP/JS/shell rules have repeatedly drifted. The only
  sanctioned pair is `target_for()` in `shive-run` ↔ `shive_target_for()` in `config.php`
  (always `<root>/<basename>`), pinned by a test. Container matching lives only in
  `docker_linked()`; derived schedule fields are stripped only in `shive_strip_derived()`.
- **Containers must always come back up.** `guard_quiesce` records `quiesced=true` in the state
  file *before* the first `docker stop`; `shive-recover` restarts containers for dead PIDs with
  `quiesced && !resumed`. Dry-runs must not set `quiesced`. Background commands must close the
  flock fd (`9>&-`), and `docker stop` runs backgrounded + `wait` so a trapped SIGTERM isn't
  deferred.
- **Shell under `set -o pipefail`**: never `... | grep -q` to decide something (SIGPIPE → false
  failure; a static check in `run.sh` rejects it). Pipe stages that may legitimately match nothing
  need `|| true`.
- **Retention only touches this schedule's tag prefix**, always keeps the newest snapshot, and
  skips snapshots with the ZFS user property `shive:important=1`.
- Replication base selection: resume token → newest shared snapshot (`-I`) → source bookmark
  `#shive-<hex>-bm-<targetid>` (`-i`, verified by GUID via `bm_check`) → full send if target absent
  → refuse otherwise. Never `receive -F` onto live data.
- Any value that ends up in a shell command or in the bash-sourced `shive.cfg` is validated
  server-side and quoted (`escapeshellarg`, single-quoted settings).

### On-box paths

Durable config on flash: `/boot/config/plugins/shive/` (`shive.cfg`, `schedules/<id>.json`,
`mappings.json`, `used_ids`, `shive.cron`, `state/*.last.json`) - avoid needless writes (USB wear).
Volatile: `/var/local/shive/` (run state, locks, discovery cache) and `/var/log/shive/<id>/`.
