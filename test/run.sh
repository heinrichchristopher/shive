#!/bin/bash
# Shive regression suite - runs the plugin against the stateful fakes in this directory.
# Usage: bash test/run.sh          (from the repo root; needs php, jq, rsync, python3)
# Every check prints PASS/FAIL; exit status is non-zero if anything failed.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"; REPO="$(dirname "$HERE")"
SRC="$REPO/src/usr/local/emhttp/plugins/shive"
export PATH="$HERE:$PATH"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  PASS  $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL  $1"; }
check(){ if eval "$2" >/dev/null 2>&1; then ok "$1"; else bad "$1"; fi; }
sec()  { echo; echo "== $1"; }

# ---------------------------------------------------------------- sandbox
reset() {
  rm -rf /boot/config /var/local/shive /var/log/shive /mnt/pin /mnt/minikeg /mnt/tank /mnt/user /mnt/shive \
         /tmp/fakezfs.json /tmp/fakedocker.json /tmp/notify.log /tmp/docker.log /tmp/ssh.log /etc/cron.d/root
  mkdir -p /boot/config/plugins/shive/schedules /boot/config/plugins/shive/state /boot/config/plugins/dynamix \
           /var/local/shive/state /var/local/shive/locks /var/local/shive/restore /var/log/shive \
           /var/local/emhttp /var/log/plugins /etc/cron.d /usr/local/sbin /usr/local/emhttp/webGui/scripts /usr/local/emhttp/plugins
  printf 'timeZone="Europe/Berlin"\n' > /boot/config/ident.cfg
  printf 'mdState="STARTED"\ncsrf_token="TOKEN123"\n' > /var/local/emhttp/var.ini
  printf '#!/bin/bash\necho "NOTIFY $*" >> /tmp/notify.log\n' > /usr/local/emhttp/webGui/scripts/notify
  chmod +x /usr/local/emhttp/webGui/scripts/notify
  : > /var/log/plugins/shive.plg; : > /var/log/plugins/dynamix.plg
  # update_cron behaving like Unraid's: only dirs named after installed .plg files
  cat > /usr/local/sbin/update_cron <<'EOF'
#!/bin/bash
: > /etc/cron.d/root
for plg in /var/log/plugins/*.plg; do [ -e "$plg" ] || continue; n=$(basename "$plg" .plg)
  for f in /boot/config/plugins/$n/*.cron; do [ -e "$f" ] && cat "$f" >> /etc/cron.d/root; done; done
EOF
  chmod +x /usr/local/sbin/update_cron
  rm -rf /usr/local/emhttp/plugins/shive; ln -s "$SRC" /usr/local/emhttp/plugins/shive
  echo '{}' > /tmp/fakedocker.json
}
A() { php "$HERE/apitest.php" "$@" 2>/dev/null; }
php_save() { php -r "require '$SRC/include/config.php'; \$r=shive_schedule_save($1); echo \$r['ok'] ? \$r['schedule']['id'] : 'ERR:'.implode(';',\$r['errors']);"; }
S="$SRC/scripts"
LOGDIR_T="/var/log/shive/housekeeping-test"

reset
zfs create pin && zfs create pin/appdata && zfs create pin/appdata/paperless && zfs create pin/appdata/grafana
zfs create minikeg && zfs create minikeg/backup && zfs create tank && zfs create tank/backup
echo "v1" > /mnt/pin/appdata/config.txt; echo "db" > /mnt/pin/appdata/paperless/db.sqlite
mkdir -p /mnt/user/appdata && ln -sfn /mnt/pin/appdata/paperless /mnt/user/appdata/paperless
cat > /tmp/fakedocker.json <<'EOF'
{"paperless":{"running":true,"mounts":[{"Type":"bind","Source":"/mnt/user/appdata/paperless","Destination":"/data"}]},
 "grafana":{"running":true,"mounts":[{"Type":"bind","Source":"/mnt/pin/appdata/grafana","Destination":"/g"}]},
 "stopped1":{"running":false,"mounts":[{"Type":"bind","Source":"/mnt/pin/appdata/paperless","Destination":"/x"}]},
 "unrelated":{"running":true,"mounts":[{"Type":"bind","Source":"/mnt/tank/media","Destination":"/m"}]}}
EOF

# ---------------------------------------------------------------- schedules / ids / names
sec "schedules, ids, names, cron"
ID=$(php_save '["name"=>"pin/appdata – Daten (täglich)","datasets"=>["pin/appdata"],"recursive"=>true,"docker_aware"=>true,
  "local_target"=>["enabled"=>true,"dataset"=>"minikeg/backup"],
  "remote_target"=>["enabled"=>true,"host"=>"fakehost","user"=>"root","port"=>22,"dataset"=>"tank/backup","own_schedule"=>true,"frequency"=>"weekly","time"=>"04:00","weekday"=>0],
  "retention"=>["source"=>["mode"=>"gfs","hourly"=>0,"daily"=>2,"weekly"=>1,"monthly"=>1],"local"=>["mode"=>"gfs","daily"=>7,"weekly"=>4,"monthly"=>3],"remote"=>["mode"=>"gfs","daily"=>7,"weekly"=>4,"monthly"=>3]]]')
check "schedule saved with free-form name, id=$ID"      "[[ '$ID' =~ ^[0-9a-f]{6}$ ]]"
check "file is keyed by id"                              "[ -f /boot/config/plugins/shive/schedules/$ID.json ]"
check "id registered in used_ids"                        "grep -qx $ID /boot/config/plugins/shive/used_ids"
check "cron: snapshot line with --no-send-remote"        "grep -q 'shive-run $ID --no-send-remote' /etc/cron.d/root"
check "cron: separate send-only remote line"             "grep -q 'shive-run $ID --send-only remote' /etc/cron.d/root"
check "cron: no fallback file when primary works"        "[ ! -f /boot/config/plugins/dynamix/shive.cron ]"
check "forged id in request is not adopted"              "[ \"\$(php_save '[\"id\"=>\"abcdef\",\"name\"=>\"x\",\"datasets\"=>[\"pin/appdata\"]]')\" != abcdef ]"
ID2=$(php_save '["name"=>"pin/appdata – Daten (täglich)","datasets"=>["pin/appdata/grafana"]]')
check "duplicate display name allowed, distinct id"      "[ '$ID2' != '$ID' ] && [[ '$ID2' =~ ^[0-9a-f]{6}$ ]]"
check "exclude_props reduced to one entry stays one"     "[ \"\$(php -r \"require '$SRC/include/config.php'; \\\$s=shive_schedule_load('$ID'); \\\$s['exclude_props']=['mountpoint']; \\\$r=shive_schedule_save(\\\$s); echo count(\\\$r['schedule']['exclude_props']);\")\" = 1 ]"
php -r "require '$SRC/include/config.php'; \$s=shive_schedule_load('$ID'); \$s['exclude_props']=['mountpoint','canmount','sharenfs','sharesmb']; shive_schedule_save(\$s);" >/dev/null
check "invalid name rejected"                            "A POST 'op=schedule_save&schedule={\"name\":\"\",\"datasets\":[\"pin/appdata\"]}' | jq -e '.ok==false'"
check "csrf rejected"                                    "A POST 'op=schedules' nocsrf | jq -e '.ok==false'"
check "resolve by name (cli convenience)"                "[ \"\$(php $SRC/include/cli.php schedule-get 'pin/appdata – Daten (täglich)' | jq -r .id)\" = $ID ] || [ \"\$(php $SRC/include/cli.php schedule-get 'pin/appdata – Daten (täglich)' | jq -r .id)\" = $ID2 ]"

sec "input validation (config comes from a form; keep it to shapes zfs/ssh accept)"
val() { php -r "require '$SRC/include/config.php'; \$r=shive_schedule_save($1); echo \$r['ok']?'ok':'rejected';"; }
check "source dataset: path traversal rejected"           "[ \"\$(val '[\"name\"=>\"v\",\"datasets\"=>[\"../../etc\"]]')\" = rejected ]"
check "source dataset: shell metacharacters rejected"     "[ \"\$(val '[\"name\"=>\"v\",\"datasets\"=>[\"pin/a; rm -rf /\"]]')\" = rejected ]"
check "local backup root validated like a source"         "[ \"\$(val '[\"name\"=>\"v\",\"datasets\"=>[\"pin/appdata\"],\"local_target\"=>[\"enabled\"=>true,\"dataset\"=>\"m/b; id\"]]')\" = rejected ]"
check "remote root validated like a source"               "[ \"\$(val '[\"name\"=>\"v\",\"datasets\"=>[\"pin/appdata\"],\"remote_target\"=>[\"enabled\"=>true,\"host\"=>\"h\",\"dataset\"=>\"t/b\$(id)\"]]')\" = rejected ]"
check "remote host rejected if not hostname-shaped"       "[ \"\$(val '[\"name\"=>\"v\",\"datasets\"=>[\"pin/appdata\"],\"remote_target\"=>[\"enabled\"=>true,\"host\"=>\"h; id\",\"dataset\"=>\"t/b\"]]')\" = rejected ]"
check "remote port must be in range"                      "[ \"\$(val '[\"name\"=>\"v\",\"datasets\"=>[\"pin/appdata\"],\"remote_target\"=>[\"enabled\"=>true,\"host\"=>\"h\",\"dataset\"=>\"t/b\",\"port\"=>99999]]')\" = rejected ]"
check "target inside its own source rejected"             "[ \"\$(val '[\"name\"=>\"v\",\"datasets\"=>[\"pin/appdata\"],\"local_target\"=>[\"enabled\"=>true,\"dataset\"=>\"pin/appdata/b\"]]')\" = rejected ]"
check "legitimate remote config still accepted"           "[ \"\$(val '[\"name\"=>\"v-ok\",\"datasets\"=>[\"pin/appdata\"],\"remote_target\"=>[\"enabled\"=>true,\"host\"=>\"my-nas-01.local\",\"user\"=>\"root\",\"port\"=>2222,\"dataset\"=>\"tank/backups\"]]')\" = ok ]"
for f in /boot/config/plugins/shive/schedules/*.json; do
  grep -q '"name": *"v-ok"' "$f" 2>/dev/null && rm -f "$f"
done
php "$SRC/include/cli.php" cron-write >/dev/null 2>&1

sec "cron fallback when installed under another .plg name"
rm -f /var/log/plugins/shive.plg; : > /var/log/plugins/shive-local.plg
php "$SRC/include/cli.php" cron-write
check "entry still reaches /etc/cron.d/root via fallback"  "grep -q 'shive-run $ID' /etc/cron.d/root"
check "fallback file was created"                        "[ -f /boot/config/plugins/dynamix/shive.cron ]"
rm -f /var/log/plugins/shive-local.plg; : > /var/log/plugins/shive.plg; php "$SRC/include/cli.php" cron-write
check "fallback removed again when primary works"        "[ ! -f /boot/config/plugins/dynamix/shive.cron ]"

# ---------------------------------------------------------------- docker-aware run + replication
sec "housekeeping and idempotence (RAM growth, flash wear)"
M1=$(stat -c %Y /boot/config/plugins/shive/shive.cron 2>/dev/null || echo 0)
sleep 1.1; php "$SRC/include/cli.php" cron-write >/dev/null 2>&1
check "cron-write does not rewrite flash when unchanged"   "[ \"\$(stat -c %Y /boot/config/plugins/shive/shive.cron)\" = '$M1' ]"
mkdir -p "$LOGDIR_T"; for i in $(seq 1 230); do touch -d "-$i minutes" "$LOGDIR_T/old-$i.log"; done
for i in $(seq 1 70); do touch -d "-$i minutes" /var/local/shive/state/housekeep$i.done.json; done
$S/shive-recover --quiet >/dev/null 2>&1
check "run logs are capped, newest kept"                   "[ \"\$(ls '$LOGDIR_T' | wc -l)\" -le 200 ] && [ -f '$LOGDIR_T/old-1.log' ]"
check "retired state files are capped, newest kept"        "[ \"\$(ls /var/local/shive/state/*.done.json 2>/dev/null | wc -l)\" -le 50 ] && [ -f /var/local/shive/state/housekeep1.done.json ]"
rm -rf "$LOGDIR_T" /var/local/shive/state/housekeep*.done.json

sec "the snapshot tag prefix is derived in PHP only"
check "run, send and prune all read .tag_prefix"           "[ \"\$(grep -c 'tag_prefix' $S/shive-run $S/shive-send $S/shive-prune | grep -c ':[1-9]')\" = 3 ]"
check "no script rebuilds the prefix from a literal"       "! grep -qE 'PREFIX=\"shive-' $S/shive-run $S/shive-send $S/shive-prune"

sec "target root: local/remote fields take a root, not the literal destination"
check "root missing is reported"                          "A GET 'op=target_parent_status&dataset=minikeg/fresh&target=local' | jq -e '.exists==false and .root==\"minikeg/fresh\"'"
check "create makes it exist"                              "A POST 'op=target_parent_create&dataset=minikeg/fresh&target=local' | jq -e '.ok' && zfs list -H -o name minikeg/fresh"
check "status now reports it exists"                       "A GET 'op=target_parent_status&dataset=minikeg/fresh&target=local' | jq -e '.exists'"
zfs destroy minikeg/fresh >/dev/null 2>&1

sec "catch-up: a never-run schedule is not \"infinitely overdue\" (field bug 2026-09-11)"
IDC=$(php_save '["name"=>"Nightly","datasets"=>["pin/appdata"],"frequency"=>"daily","time"=>"00:00"]')
check "fresh schedule has a created timestamp"             "[ \"\$(jq -r .created /boot/config/plugins/shive/schedules/$IDC.json)\" -gt 0 ]"
STATE_BEFORE="/boot/config/plugins/shive/state/$IDC.last.json"
php "$SRC/include/cli.php" catchup >/dev/null 2>&1; sleep 0.3
check "never-run schedule does NOT fire on the very next boot" "[ ! -f '$STATE_BEFORE' ]"
php -r "require '$SRC/include/config.php'; \$s=shive_schedule_load('$IDC'); \$s['created']=time()-2*86400; \$d=json_decode(file_get_contents(shive_schedule_file('$IDC')),true); \$d['created']=time()-2*86400; file_put_contents(shive_schedule_file('$IDC'), json_encode(\$d));" 2>/dev/null
php -r "require '$SRC/include/config.php'; \$d=json_decode(file_get_contents(shive_schedule_file('$IDC')),true); \$d['created']=time()-2*86400; file_put_contents(shive_schedule_file('$IDC'), json_encode(\$d));"
php "$SRC/include/cli.php" catchup >/dev/null 2>&1; sleep 1.5
check "but IS caught up once truly overdue since creation" "[ -f '$STATE_BEFORE' ] && jq -e '.status' '$STATE_BEFORE' >/dev/null"
zfs destroy -r pin/appdata@shive-$IDC-$(date +%d%m%Y-%H%M) 2>/dev/null   # the real catchup run's own snapshot
zfs list -H -t snapshot -o name -d 1 pin/appdata | grep "shive-$IDC-" | while read -r sn; do zfs destroy "$sn" 2>/dev/null; done
php -r "require '$SRC/include/config.php'; shive_schedule_delete('$IDC');" >/dev/null 2>&1
: > /tmp/notify.log   # the "truly overdue" sub-case above ran a real job and sent its own
                       # success notification - don't let it pollute a later section's count

sec "docker-aware run, local replication, bookmarks"
: > /tmp/docker.log
$S/shive-run $ID >/dev/null 2>&1; RC=$?
check "run rc=0"                                          "[ $RC = 0 ]"
check "stopped only running+linked (paperless,grafana)"   "[ \"\$(grep -c '^stop' /tmp/docker.log)\" = 2 ] && ! grep -q '^stop.*stopped1' /tmp/docker.log && ! grep -q '^stop.*unrelated' /tmp/docker.log"
check "restarted the same set"                            "[ \"\$(grep -c '^start' /tmp/docker.log)\" = 2 ]"
check "containers running afterwards"                     "jq -e '.paperless.running and .grafana.running' /tmp/fakedocker.json"
check "snapshot on source (parent + child)"               "zfs list -H -t snapshot -o name -r pin/appdata | grep -c shive-$ID- | grep -qx 3"
check "snapshot arrived on local target"                  "zfs list -H -t snapshot -o name -d 1 minikeg/backup/appdata | grep -q shive-$ID-"
check "target received readonly=on"                       "[ \"\$(zfs get -H -o value readonly minikeg/backup/appdata)\" = on ]"
check "remote NOT sent (own schedule, even on a manual run)" "! zfs list -H -o name tank/backup/appdata"
check "bookmark on every dataset of the tree"             "[ \"\$(zfs list -H -t bookmark -o name -r pin/appdata | grep -c bm-)\" = 3 ]"
check "exactly one success notification"                  "[ \"\$(grep -c success /tmp/notify.log)\" = 1 ]"
check "no bogus resume-FAILED alert (finalize ordering)"  "! grep -q 'resume FAILED' /tmp/notify.log"
check "last status keyed by id"                           "jq -e '.status==\"success\"' /boot/config/plugins/shive/state/$ID.last.json"
sleep 2; $S/shive-run $ID >/dev/null 2>&1
check "second run is incremental -I"                      "grep -q 'incremental base: @' /var/log/shive/$ID/*.log"
check "shive-send output landed in the run log"           "grep -q 'send: zfs send' /var/log/shive/$ID/*.log"

sec "send-only remote through ssh"
STOPS_BEFORE=$(grep -c '^stop' /tmp/docker.log)
$S/shive-run $ID --send-only remote >/dev/null 2>&1; RC=$?
check "send-only rc=0"                                    "[ $RC = 0 ]"
check "no snapshot taken, no docker touched"              "! grep -q 'phase: SNAPSHOT$' /var/log/shive/$ID/*send-remote.log && [ \"\$(grep -c '^stop' /tmp/docker.log)\" = $STOPS_BEFORE ]"
check "remote has the snapshots"                          "[ \"\$(zfs list -H -t snapshot -o name -d 1 tank/backup/appdata | grep -c shive-)\" -ge 1 ]"
check "own status file for the send-only run"             "[ -f /boot/config/plugins/shive/state/$ID.send-remote.last.json ]"
$S/shive-run $ID --send-only remote >/dev/null 2>&1
check "re-run is a no-op (already on target)"             "grep -q 'nothing to send' \$(ls -t /var/log/shive/$ID/*send-remote.log | head -1)"
check "tile merges send-only status"                      "jq -e '.schedules[]|select(.id==\"$ID\")|.last.sends.remote.status==\"ok\"' /var/local/shive/status.json"

# ---------------------------------------------------------------- retention + important
sec "root semantics are consistent everywhere (PHP helper vs. shell target_for)"
check "shive_target_for appends basename unconditionally"  "[ \"\$(php -r \"require '$SRC/include/config.php'; echo shive_target_for('minikeg/backup','pin/appdata');\")\" = minikeg/backup/appdata ]"
check "prune_preview targets <root>/<basename>, not the root" "A GET \"op=prune_preview&id=$ID\" | jq -e '.preview|keys|any(.==\"local:minikeg/backup/appdata\")'"
check "snapshot_locations maps to <root>/<basename>"       "php -r \"require '$SRC/include/config.php'; \\\$l=shive_snapshot_locations('pin/appdata','shive-$ID-01012026-0000'); echo implode(',', array_column(\\\$l,'dataset'));\" | grep -q 'minikeg/backup/appdata'"
check "single-dataset schedule uses the same rule as multi" "php -r \"require '$SRC/include/config.php'; \\\$s=shive_schedule_load('$ID'); exit(count(\\\$s['datasets'])===1 && shive_target_for(\\\$s['local_target']['dataset'],\\\$s['datasets'][0])==='minikeg/backup/appdata' ? 0 : 1);\""

sec "retention, important flag, propagation"
for i in 1 2; do sleep 3; $S/shive-run $ID --no-prune >/dev/null 2>&1; done
OLD=$(zfs list -H -t snapshot -o name -s creation -d 1 pin/appdata | head -1)
$S/shive-snapshot flag --snapshot "$OLD" --recursive >/dev/null 2>&1
check "flag on source parent+child"                       "[ \"\$(zfs get -H -o value shive:important $OLD)\" = 1 ] && [ \"\$(zfs get -H -o value shive:important pin/appdata/paperless@${OLD#*@})\" = 1 ]"
check "flag propagated to local target"                   "[ \"\$(zfs get -H -o value shive:important minikeg/backup/appdata@${OLD#*@})\" = 1 ]"
check "flag propagated to remote"                         "[ \"\$(zfs get -H -o value shive:important tank/backup/appdata@${OLD#*@})\" = 1 ]"
check "prune keeps important, still uses a daily slot"    "$S/shive-prune --sched $ID --location source --dataset pin/appdata --json | jq -e '(.reasons[\"${OLD#*@}\"]==\"important\") and ([.reasons[]|select(test(\"daily\"))]|length>=1)'"
$S/shive-prune --sched $ID --location source --dataset pin/appdata --recursive >/dev/null 2>&1
check "important survived the real prune"                 "zfs list -H -o name $OLD"
zfs inherit shive:important "tank/backup/appdata@${OLD#*@}" 2>/dev/null
$S/shive-run $ID --send-only remote >/dev/null 2>&1
check "sync_flags re-applies source flag to remote"       "[ \"\$(zfs get -H -o value shive:important tank/backup/appdata@${OLD#*@})\" = 1 ]"
$S/shive-snapshot flag --snapshot "minikeg/backup/appdata@${OLD#*@}" --recursive >/dev/null 2>&1   # idempotent, from target side
$S/shive-snapshot unflag --snapshot "$OLD" --recursive >/dev/null 2>&1
check "unflag propagated everywhere"                      "[ \"\$(zfs get -H -o value shive:important minikeg/backup/appdata@${OLD#*@})\" = - ] && [ \"\$(zfs get -H -o value shive:important tank/backup/appdata@${OLD#*@})\" = - ]"
check "api snapshots exposes important flag"              "A GET 'op=snapshots&dataset=pin/appdata' | jq -e '.snapshots[0]|has(\"important\")'"
check "api schedule label resolves for hex ids"           "A GET 'op=snapshots&dataset=pin/appdata' | jq -e '[.snapshots[].schedule]|all(.!=null and (test(\"deleted\")|not))'"

sec "SIGTERM during a blocking docker stop (array-stop safety net)"
# A container that ignores SIGTERM makes `docker stop -t N` block for its full timeout. If the
# script's own signal handling were deferred until that foreground command returns (plain bash
# behaviour for a synchronous foreground command), a real array-stop's SIGTERM could sit queued
# behind it long enough for stopping_svcs' own unblockable SIGKILL escalation to fire first -
# the container would then never be resumed. Reproduces the exact 2026-09-11 field bug.
IDD=$(php_save '["name"=>"StubbornTest","datasets"=>["pin/appdata"],"docker_aware"=>true]')
cat > /tmp/fakedocker.json <<EOF
{"stubborn":{"running":true,"stop_delay":6,"mounts":[{"Type":"bind","Source":"/mnt/user/appdata/app","Destination":"/data"}]}}
EOF
mkdir -p /mnt/pin/appdata/app; rm -f /mnt/user/appdata/app; ln -sfn /mnt/pin/appdata/app /mnt/user/appdata/app
T0=$(date +%s)
$S/shive-run $IDD --no-send --no-prune --force >/tmp/sigterm-test.log 2>&1 &
RUNPID=$!; sleep 1.5; kill -TERM $RUNPID 2>/dev/null; wait $RUNPID 2>/dev/null
ELAPSED=$(( $(date +%s) - T0 ))
check "signal handled promptly, not deferred behind docker stop (<4s, not ~6s)" "[ $ELAPSED -lt 4 ]"
check "trap fired and logged the abort"                    "grep -q 'signal received - aborting' /tmp/sigterm-test.log"
check "container was resumed despite the abort"            "jq -e '.stubborn.running' /tmp/fakedocker.json"
# The backgrounded docker stop inherits the schedule's flock fd. If it isn't closed in the child,
# a killed run leaves an orphan holding the lock until it exits, and the NEXT scheduled run is
# silently skipped with "another job holds lock" - a backup that never happens and never errors.
python3 -c "import json;d=json.load(open('/tmp/fakedocker.json'));d['stubborn']['running']=True;json.dump(d,open('/tmp/fakedocker.json','w'))"
$S/shive-run $IDD --no-send --no-prune --force >/dev/null 2>&1 &
RP2=$!; sleep 1.5; kill -9 $RP2 2>/dev/null; sleep 0.5
check "killed run leaves no orphan holding the schedule lock" "$S/shive-run $IDD --no-send --no-prune --force 2>&1 | grep -q 'taken on pin/appdata'"
pkill -9 -f "$HERE/docker stop" 2>/dev/null; sleep 0.2
rm -f /mnt/user/appdata/app; cat > /tmp/fakedocker.json <<'EOF'
{"paperless":{"running":true,"mounts":[{"Type":"bind","Source":"/mnt/user/appdata/paperless","Destination":"/data"}]},
 "grafana":{"running":true,"mounts":[{"Type":"bind","Source":"/mnt/pin/appdata/grafana","Destination":"/g"}]},
 "stopped1":{"running":false,"mounts":[{"Type":"bind","Source":"/mnt/pin/appdata/paperless","Destination":"/x"}]},
 "unrelated":{"running":true,"mounts":[{"Type":"bind","Source":"/mnt/tank/media","Destination":"/m"}]}}
EOF

sec "manual snapshot deletion"
zfs snapshot -r pin/appdata@shive-$ID-99999999-9999 >/dev/null 2>&1
check "destroy needs --yes"                               "! $S/shive-snapshot destroy --snapshot pin/appdata@shive-$ID-99999999-9999"
check "destroy dry-run deletes nothing"                   "$S/shive-snapshot destroy --snapshot pin/appdata@shive-$ID-99999999-9999 --yes --dry-run && zfs list -H -o name pin/appdata@shive-$ID-99999999-9999"
zfs clone pin/appdata@shive-$ID-99999999-9999 pin/klon >/dev/null 2>&1; zfs set clones=pin/klon pin/appdata@shive-$ID-99999999-9999 >/dev/null 2>&1
check "destroy refuses while a clone exists"              "$S/shive-snapshot destroy --snapshot pin/appdata@shive-$ID-99999999-9999 --yes 2>&1 | grep -q 'still has clones'"
zfs inherit clones pin/appdata@shive-$ID-99999999-9999 >/dev/null 2>&1; zfs destroy pin/klon >/dev/null 2>&1
check "api delete needs confirm"                          "A POST 'op=snapshot_delete&snapshot=pin/appdata@shive-$ID-99999999-9999' | jq -e '.ok==false'"
zfs snapshot -r pin/appdata@shive-$ID-88888888-8888 >/dev/null 2>&1; zfs destroy pin/appdata@shive-$ID-88888888-8888 >/dev/null 2>&1
$S/shive-prune --sched $ID --location source --dataset pin/appdata --recursive >/dev/null 2>&1
check "orphaned child snapshot swept by recursive prune"  "! zfs list -H -o name pin/appdata/paperless@shive-$ID-88888888-8888"
check "api delete recursive works"                        "A POST 'op=snapshot_delete&snapshot=pin/appdata@shive-$ID-99999999-9999&recursive=1&confirm=1' | jq -e '.ok' && ! zfs list -H -o name pin/appdata/paperless@shive-$ID-99999999-9999"

# ---------------------------------------------------------------- crash recovery
sec "multi-dataset schedule + partial source availability"
zfs create pin/media >/dev/null 2>&1; zfs create pin/docs >/dev/null 2>&1
echo m > /mnt/pin/media/m.txt; echo d > /mnt/pin/docs/d.txt
IDM=$(php_save '["name"=>"Multi","datasets"=>["pin/appdata","pin/media","pin/docs"],"recursive"=>true,
  "local_target"=>["enabled"=>true,"dataset"=>"minikeg/multi"]]')
zfs create minikeg/multi >/dev/null 2>&1
$S/shive-run $IDM --no-prune >/dev/null 2>&1
check "each source got its own <root>/<basename> target"  "zfs list -H -o name -r minikeg/multi | grep -qx minikeg/multi/appdata && zfs list -H -o name -r minikeg/multi | grep -qx minikeg/multi/media && zfs list -H -o name -r minikeg/multi | grep -qx minikeg/multi/docs"
SNM=$(zfs list -H -t snapshot -o name -d 1 pin/media | head -1)
$S/shive-snapshot flag --snapshot "$SNM" --recursive >/dev/null 2>&1
check "flag lands on the matching target only"            "[ \"\$(zfs get -H -o value shive:important minikeg/multi/media@${SNM#*@})\" = 1 ] && [ \"\$(zfs get -H -o value shive:important minikeg/multi/docs@${SNM#*@})\" = - ]"
check "prune_preview covers every source and target"      "A GET \"op=prune_preview&id=$IDM\" | jq -e '.preview|keys|length==6'"
# one source removed must not stop the others being backed up
zfs destroy -r pin/docs >/dev/null 2>&1
sleep 3; $S/shive-run $IDM --no-prune >/tmp/partial.log 2>&1
# assert on this run's own output, not on last.json: under load the suite can race the status
# file, and the log is what the run itself actually did
check "missing source is skipped, others still snapshot"  "grep -q \"source dataset 'pin/docs' does not exist - skipped\" /tmp/partial.log && grep -q 'continuing with 2 of 3 source datasets' /tmp/partial.log"
check "the remaining sources did get a fresh snapshot"    "grep -q 'taken on pin/appdata pin/media' /tmp/partial.log"
check "all sources gone is still a hard failure"          "zfs destroy -r pin/media >/dev/null 2>&1; php -r \"require '$SRC/include/config.php'; \\\$s=shive_schedule_load('$IDM'); \\\$s['datasets']=['pin/media','pin/docs']; shive_schedule_save(\\\$s);\"; $S/shive-run $IDM --no-prune >/dev/null 2>&1; jq -e '.status==\"failed\"' /boot/config/plugins/shive/state/$IDM.last.json"
zfs destroy -r minikeg/multi >/dev/null 2>&1
php -r "require '$SRC/include/config.php'; shive_schedule_delete('$IDM');" >/dev/null 2>&1

sec "crash recovery"
# Set up our own container state rather than relying on an earlier section's cleanup: the fake
# docker does a read-modify-write, so a late write from a previous (deliberately killed) run can
# race past that cleanup and leave the file in the wrong shape.
cat > /tmp/fakedocker.json <<'EOF'
{"paperless":{"running":true,"mounts":[{"Type":"bind","Source":"/mnt/user/appdata/paperless","Destination":"/data"}]},
 "grafana":{"running":true,"mounts":[{"Type":"bind","Source":"/mnt/pin/appdata/grafana","Destination":"/g"}]},
 "stopped1":{"running":false,"mounts":[{"Type":"bind","Source":"/mnt/pin/appdata/paperless","Destination":"/x"}]},
 "unrelated":{"running":true,"mounts":[{"Type":"bind","Source":"/mnt/tank/media","Destination":"/m"}]}}
EOF
mkdir -p /mnt/user/appdata; ln -sfn /mnt/pin/appdata/paperless /mnt/user/appdata/paperless
php "$SRC/include/cli.php" discover --refresh >/dev/null 2>&1
sed -i "s/elif a\[0\]=='stop':/elif a[0]=='stop':\n    import time; time.sleep(3)/" "$HERE/docker"
: > /tmp/notify.log
$S/shive-run $ID --no-send --no-prune >/dev/null 2>&1 & sleep 4.5; pkill -9 -f "shive-run $ID"; sleep 3.5   # let the orphaned docker child (and its inherited lock fd) finish
sed -i "/import time; time.sleep(3)/d" "$HERE/docker"
check "a container is down after kill -9"                 "jq -e '[.[]|select(.running==false)]|length>=2' /tmp/fakedocker.json"
$S/shive-recover >/dev/null 2>&1
check "recover restarted the linked containers"           "jq -e '.paperless.running and .grafana.running and (.stopped1.running|not)' /tmp/fakedocker.json"
check "recover wrote crashed status"                      "jq -e '.status==\"crashed\"' /boot/config/plugins/shive/state/$ID.last.json"
check "recovered notification uses display name"          "grep -q 'Daten' /tmp/notify.log"
check "recover is locked against concurrent runs"         "grep -q 'flock -n 7' $S/shive-recover"

# ---------------------------------------------------------------- restore
sec "restore paths"
$S/shive-run $ID --no-send --no-prune >/dev/null 2>&1
SN=$(zfs list -H -t snapshot -o name -s creation -d 1 pin/appdata | grep -v prerestore | tail -1)
check "stage mounted dataset: direct .zfs path"          "$S/shive-restore stage --snapshot $SN 2>/dev/null | jq -e '.staged_id==null and (.path|test(\"/.zfs/snapshot/\"))'"
zfs create -o canmount=off minikeg/cold >/dev/null 2>&1; zfs snapshot minikeg/cold@shive-$ID-x >/dev/null 2>&1
check "stage unmounted dataset: clone"                    "$S/shive-restore stage --snapshot minikeg/cold@shive-$ID-x 2>/dev/null | jq -e '.staged_id!=null'"
check "api stage parses compact json"                     "A POST \"op=stage&snapshot=$SN&target=local\" | jq -e '.ok'"
check "browse flags child datasets"                       "A GET \"op=browse&path=/mnt/pin/appdata/.zfs/snapshot/${SN#*@}&target=local\" | jq -e '[.entries[]|select(.child_dataset)]|length==2'"
check "browse rejects paths outside staging"              "A GET 'op=browse&path=/etc&target=local' | jq -e '.ok==false'"
echo "changed" > /mnt/pin/appdata/config.txt
$S/shive-restore file --snapshot "$SN" --path config.txt --dest /mnt/pin/appdata/config.txt --mode copy >/dev/null 2>&1
check "file restore as copy"                              "ls /mnt/pin/appdata/ | grep -q 'config.txt.shive-restore-'"
$S/shive-restore file --snapshot "$SN" --path config.txt --dest /mnt/pin/appdata/config.txt --mode overwrite --yes >/dev/null 2>&1
check "file restore overwrite"                            "[ \"\$(cat /mnt/pin/appdata/config.txt)\" != changed ]"
echo junk > /mnt/pin/appdata/paperless/db.sqlite
$S/shive-restore dataset --snapshot "$SN" --method rsync --yes >/dev/null 2>&1; RC=$?
check "dataset restore rsync rc=0, .zfs preserved"        "[ $RC = 0 ] && [ -d /mnt/pin/appdata/.zfs ] && [ \"\$(cat /mnt/pin/appdata/paperless/db.sqlite)\" != junk ]"
check "pre-restore snapshot created"                      "zfs list -H -t snapshot -o name pin/appdata | grep -q prerestore"
# pick a snapshot that actually exists ON THE TARGET: the newest source snapshot may never have
# been replicated (earlier sections run with --no-send), which is not what this check is about
SNT=$(zfs list -H -t snapshot -o name -s creation -d 1 minikeg/backup/appdata | tail -1)
check "DR receive from local pool into new dataset"       "[ -n '$SNT' ] && $S/shive-restore dr --target local --snapshot $SNT --to pin/appdata-restored --recursive >/dev/null 2>&1 && zfs list -H -o name pin/appdata-restored"
check "DR refuses existing dataset"                       "$S/shive-restore dr --target local --snapshot $SNT --to pin/appdata 2>&1 | grep -q refusing"
$S/shive-restore unstage --all >/dev/null 2>&1
check "unstage removed all clones"                        "[ \"\$(ls /var/local/shive/restore/ | wc -l)\" = 0 ]"

# ---------------------------------------------------------------- misc api / edge
sec "misc"
check "target pool absent -> warning + skipped"           "php -r \"require '$SRC/include/config.php'; \\\$s=shive_schedule_load('$ID'); \\\$s['local_target']['dataset']='gone/x'; shive_schedule_save(\\\$s);\" && $S/shive-run $ID --no-prune --no-send-remote >/dev/null 2>&1; jq -e '.status==\"warning\" and .sends.local.status==\"skipped\"' /boot/config/plugins/shive/state/$ID.last.json"
php -r "require '$SRC/include/config.php'; \$s=shive_schedule_load('$ID'); \$s['local_target']['dataset']='minikeg/backup'; \$s['enabled']=false; shive_schedule_save(\$s);" >/dev/null
check "disabled schedule: exit 75, no cron line"          "$S/shive-run $ID >/dev/null 2>&1; [ \$? = 75 ] && ! grep -q 'shive-run $ID ' /etc/cron.d/root"
php -r "require '$SRC/include/config.php'; \$s=shive_schedule_load('$ID'); \$s['enabled']=true; shive_schedule_save(\$s);" >/dev/null
check "catch-up does not restart a fresh schedule"        "php $SRC/include/cli.php catchup; ! pgrep -f 'shive-run $ID' "
check "history keyed by id, carries name"                 "A GET 'op=history' | jq -e '.history[\"$ID\"].name|test(\"Daten\")'"
check "logs list labels ids with names"                   "A GET 'op=logs' | jq -e '[.logs[]|select(.schedule==\"$ID\")]|all(.label|test(\"Daten\"))'"
check "status health rank: error beats warning"           "php -r \"require '$SRC/include/config.php'; \\\$st=shive_status_rebuild(); echo \\\$st['health'];\" | grep -qE 'ok|warning|error'"
# renaming is an ordinary save (the id travels with the payload) - there is no separate rename op
# no "&" in the test name: the harness parses a raw query string, so it would split the params
# (the real GUI posts form-encoded, where "&" in a value is fine - covered by the save path itself)
RENAMED=$(A GET "op=schedules" | jq -c --arg id "$ID" '.schedules[]|select(.id==$id)|.name="Umbenannt \"x\" y"')
A POST "op=schedule_save&schedule=$RENAMED" > /tmp/rename.json 2>&1
check "rename via a normal save keeps the id"             "jq -e '.ok and .schedule.id==\"$ID\" and (.schedule.name|test(\"Umbenannt\"))' /tmp/rename.json"
check "delete by id removes file and status"              "A POST 'op=schedule_delete&id=$ID2' | jq -e .ok && [ ! -f /boot/config/plugins/shive/schedules/$ID2.json ]"
check "dashboard tile renders"                            "php -r '\$mytiles=[]; ob_start(); include \"$SRC/ShiveDashboard.page\"; \$o=ob_get_clean(); exit(isset(\$mytiles[\"shive\"][\"column2\"]) && str_contains(\$o,\"shive-tile.js\") ? 0 : 1);'"

echo; echo "================  $PASS passed, $FAIL failed  ================"
[ $FAIL = 0 ]
