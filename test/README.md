# Sandbox simulator (not shipped in the plugin package)

Stateful fakes used for the 2026-09-08 audit: `zfs`, `zpool` (state in /tmp/fakezfs.json, snapshot
contents materialised under /mnt/<pool>/.zfs/snapshot/), `docker` (/tmp/fakedocker.json), `findmnt`
(maps paths to fake datasets), `ssh` (runs the remote command locally, logs to /tmp/ssh.log).
`apitest.php GET|POST 'op=...&k=v' [nocsrf]` drives include/api.php.
`run.sh` is the regression suite (76 checks): schedules/ids/names, cron incl. the update_cron
fallback, docker-aware runs, local + ssh replication, send-only, bookmarks, retention + important
flag propagation/sync, manual deletion, crash recovery, every restore path, API ops, tile.

Setup: symlink src/usr/local/emhttp/plugins/shive to /usr/local/emhttp/plugins/shive, put this dir
first in PATH, create /boot/config/ident.cfg (timeZone) and /var/local/emhttp/var.ini (mdState,
csrf_token), a stub /usr/local/emhttp/webGui/scripts/notify, then `zfs create` a pool layout.
Known gaps vs. real ZFS: no encryption/keys, no property inheritance semantics, `-R -I` child
handling approximated, no `receive -F`.

## Harness flakiness under load

`run.sh`'s log-file and same-minute snapshot-naming checks depend on real wall-clock
timestamps (second granularity). Run many times back-to-back in a loaded/shared environment,
an occasional check can flake (log file picked by `ls -t` on a same-second tie, or a
subprocess-fork-heavy step taking longer than its neighbour). This is sandbox scheduling
jitter, not a product defect - the underlying logic for every check that has flaked here
(retention/important-flag propagation, replication) was independently confirmed via a
deterministic unit test with synthetic timestamps and via isolated repeated reproduction of
the real end-to-end path. If a run fails, re-run it before assuming a regression; a check
that fails consistently across several consecutive runs is the one worth investigating.
