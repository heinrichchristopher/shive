# Testing Shive on a live Unraid box without risking data

Order matters: every step below is reversible and touches only scratch objects until step 7.

## 1. Build and install locally

```
./build.sh                                  # builds build/shive-<ver>-noarch-1.txz, validates + patches shive.plg,
                                            # and writes build/local/shive.plg (file:// URL, no GitHub release needed)
mkdir -p /boot/config/plugins/shive
cp build/shive-*.txz /boot/config/plugins/shive/
cp build/local/shive.plg /boot/config/plugins/
plugin install /boot/config/plugins/shive.plg
```
The local .plg must be named `shive.plg`: `update_cron` only scans `/boot/config/plugins/<name>/`
for names present in `/var/log/plugins`. After the first save, `grep shive /etc/cron.d/root`.

## 0. Regression suite (no Unraid needed)

`bash test/run.sh` runs 76 checks against the stateful simulator in `test/` (needs php, jq, rsync,
python3; run as root in a throwaway container - it writes under /boot, /var, /mnt). Run it before
every release.
Check: `Settings → Shive` opens, the dashboard tile shows "No schedules yet".

## 2. Scratch datasets (source + local target)

```
zfs create -o recordsize=16K pin/shivetest
zfs create pin/shivetest/child
dd if=/dev/urandom of=/mnt/pin/shivetest/a.bin bs=1M count=50
dd if=/dev/urandom of=/mnt/pin/shivetest/child/b.bin bs=1M count=20
zfs create minikeg/shivetest-backup-parent          # target *parent* must exist
```

## 3. Scratch container

```
docker run -d --name shivetest -v /mnt/pin/shivetest/child:/data alpine sh -c 'while true; do date >> /data/log; sleep 2; done'
```
`Settings → Shive → Containers → Rescan` must list `shivetest → pin/shivetest/child`.
If it shows *unresolved*, the bind path did not resolve – enter the dataset in the override column.

## 4. Schedule + dry run

Create schedule `test`: datasets `pin/shivetest` (recursive), hourly, Docker awareness on,
local target root `minikeg/shivetest-backup-parent` (Shive creates `.../shivetest` under it automatically), retention source GFS 2/1/1, local age 1 day.
Click **Dry run**, then *History & Logs* → open the log. Expect every `zfs`/`docker` command
logged as `DRY-RUN:` and the container never stopped.

## 5. Real run, then verify each contract

**Run** (confirm). Then:
```
zfs list -t snapshot -r pin/shivetest              # shive-<hex>-DDMMYYYY-HHMM on parent AND child
zfs list -t snapshot -r minikeg/shivetest-backup-parent
zfs list -t bookmark pin/shivetest                 # pin/shivetest#shive-<hex>-bm-local-minikeg-…
docker inspect -f '{{.State.Running}}' shivetest   # true
grep -c . /mnt/pin/shivetest/child/log             # keeps growing => container resumed
cat /boot/config/plugins/shive/state/test.last.json | jq .
```
Run twice more (hourly cron or **Run**) – the second send must log `incremental base: @…`.

## 6. Failure paths (this is the part worth doing)

* **Crash recovery:** start a run, in another shell `pkill -9 -f 'shive-run test'` while it
  is in QUIESCE/SNAPSHOT (watch `jq .phase /var/local/shive/state/test.json`).
  Then run `shive-recover`. Container must be running again; notification "recovered";
  `test.last.json` status `crashed`.
* **Target offline:** `zpool export minikeg` (only with the scratch pool, or use a USB stick pool),
  run → status `warning`, local send `skipped`, snapshot still taken. `zpool import minikeg`, run again →
  `-I` catches up all missed snapshots on the target.
* **Stop failure:** `docker pause shivetest` then Run → stop times out → job aborts *before* the snapshot,
  container gets `docker start` (no-op on paused; unpause manually) – verify the alert says "failed to stop".
* **Reboot path:** `started` only rewrites cron; recovery + catch-up run at `docker_started` (verified event names on 7.2.8).
* **Array stop:** trigger a run, stop the array. `stopping_svcs` must terminate the job and the
  container must be running before Docker itself shuts down (check syslog `shive:` lines).
* **Retention:** *Prune preview* on the schedule; compare keep/destroy with the policy. Nothing is deleted by preview.

## 6b. Independent remote schedule

Set the remote target to "own send schedule", weekly. Then check:

```
cat /boot/config/plugins/shive/shive.cron   # snapshot line has --no-send-remote, second line --send-only remote
/usr/local/emhttp/plugins/shive/scripts/shive-run <id-shown-in-overview> --send-only remote --dry-run
```
The dry run must log `send-only (remote): using existing snapshot shive-<hex>-…` and must NOT log a
`zfs snapshot` or any `docker stop`. Run it for real after two snapshot runs — the target receives both.

## 7. Restore

* Browse a snapshot → open `child/` → *Restore…* `log` as copy → verify `log.shive-restore-<ts>` next to it.
* Same with overwrite → confirm → file replaced, mtime/owner preserved.
* Browse a snapshot **on minikeg** (unmounted target) → a clone `minikeg/shive-restore/<id>` appears
  (`zfs list -r minikeg/shive-restore`) and vanishes when you close the tree or after the TTL.
* *Restore dataset* with rsync method: append junk to `a.bin`, restore, checksum matches snapshot;
  `shive-prerestore-*` snapshot exists; container was cycled.
* DR: *Receive as new dataset* from minikeg → `pin/shivetest-restored` exists, live dataset untouched.

## 8. Clean up

```
docker rm -f shivetest
# delete schedule "test" in the GUI (removes cron + status), then:
zfs destroy -r pin/shivetest; zfs destroy -r pin/shivetest-restored
zfs destroy -r minikeg/shivetest-backup-parent
```

## 9. Going live on appdata

Create the real schedule (`pin/appdata`, recursive, Docker awareness, local target
`minikeg/appdata-backup`), **Dry run** first and read the "linked containers" line in the log –
that list is exactly what will be stopped. First real run at a quiet time: the initial full send of
appdata to minikeg is the only long container-independent step; containers are back right after the
snapshot. Keep the remote target disabled until `Test connection` is green and one manual
`shive-send --dry-run` against it looks right.

## Manual verification log (real Unraid, real ZFS)

Checked on 7.3.2 / ZFS 2.3.4 against scratch datasets (`pin/shivetest`, `minikeg/backup/shivetest`),
schedule id `dc749d`, single-dataset recursive schedule with a local target.

1. **DONE (2026-09-11)** - `zfs send -R -I` with a child dataset created after the baseline send:
   the new child (`beta`) arrived on the target in the same incremental stream, full content
   verified via `diff` after a manual `zfs mount` (targets are `canmount`/`mountpoint`-excluded
   and received with `-u`, so they never auto-mount - that is by design, not a bug).
2. **DONE (2026-09-11)** - `readonly=on` on the target root propagates to every child dataset via
   ZFS property inheritance (`zfs get -r readonly` shows `SOURCE=inherited` on children); no
   explicit per-child `-o readonly=on` needed.
3. **DONE (2026-09-11)** - bookmark fallback: with the shared snapshot destroyed on the source,
   the next send correctly used `zfs send -i <bookmark>` instead of `-R -i #bookmark` (which ZFS
   rejects), log line `incremental base: bookmark #... (shared snapshot already pruned;
   intermediates lost)`, target received the delta and ended up in sync, status success.
4. **DONE (2026-09-11)** - container discovery on Docker 29: the `|SHIVE|` separator parses
   correctly. Found and fixed along the way: a bind-mounted *subdirectory* (not a dataset's own
   root - e.g. /var/lib/docker bound from `<pool>/system/docker`) made findmnt report
   `dataset[/subpath]`, which was taken as the dataset name verbatim (invalid, never matched
   anything). Now stripped to the real dataset name.
5. TODO - `notify -l` parameter on 7.3 (drop it from `common.sh` if notifications arrive empty).
6. **DONE (2026-09-11)** - found and fixed a real bug along the way: `docker stop` ran as a
   blocking foreground command, so a container ignoring SIGTERM (or just a long stop timeout)
   deferred `stopping_svcs`' own SIGTERM until the stop finished - if that took longer than
   `stopping_svcs`' 60s SIGKILL-escalation window, the resume trap never ran and the container
   stayed down after an array stop. Fixed (`docker stop` backgrounded + `wait`, which bash does
   interrupt promptly on a trapped signal) and reproduced/verified both before and after the fix,
   live and in the automated suite. A full real array-stop test (not just a simulated SIGTERM)
   is still open if you want the belt-and-braces version.
7. **DONE (2026-09-11)** - `zfs set`/`zfs get`/`zfs inherit shive:important` on a real snapshot:
   `set` -> value 1, SOURCE=local; `inherit` -> value -, SOURCE=- . Confirms the important-flag
   feature's storage mechanism works as designed on the target ZFS version.

All seven items verified. See docs/ARCHITECTURE.md for the two real bugs found and fixed along
the way (findmnt bracket notation for bind-mounted subdirectories; SIGTERM deferred behind a
blocking `docker stop`).

## Known limitations

* `docker compose` stacks: containers are stopped/started individually in discovery order,
  not in compose dependency order.
* Bind mounts through `/mnt/user` resolve only if the same path exists on one of the pools
  (exclusive shares are fine); otherwise use an override.
* Multiple source datasets in one schedule land as `<target>/<basename>` – basenames must be unique.
* Remote host needs: root (or a user with zfs allow) SSH key login in BatchMode, `zfs`/`find`/`rsync` in PATH.
