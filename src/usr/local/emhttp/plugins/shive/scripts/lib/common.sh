#!/bin/bash
# shive - shared shell library. Sourced by all shive-* scripts.
# Conventions: functions prefixed with t_ operate on a *target* (local or ssh),
# everything else is local. All destructive commands go through run_cmd so
# --dry-run is honoured uniformly.

# cron gives us a minimal PATH (typically /usr/bin:/bin) but zfs, zpool and flock live in
# /usr/sbin and /sbin - without this every cron-started run dies in PRECHECK. Appended, not
# prepended: the caller's PATH stays authoritative, we only fill in what cron leaves out.
export PATH="$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

SHIVE_PLG="/boot/config/plugins/shive"
SHIVE_EMHTTP="/usr/local/emhttp/plugins/shive"
SHIVE_VAR="/var/local/shive"
SHIVE_CLI="php ${SHIVE_EMHTTP}/include/cli.php"
NOTIFY="/usr/local/emhttp/webGui/scripts/notify"

DRY_RUN=${DRY_RUN:-0}

# ---- global config ---------------------------------------------------------
# shellcheck disable=SC2034  # every variable set here is read by the scripts that source this
load_cfg() {
  LOG_DIR="/var/log/shive"; NOTIFY_ON_SUCCESS="yes"; DOCKER_STOP_TIMEOUT=60
  SSH_KEY=""; SSH_OPTS="-o BatchMode=yes -o ConnectTimeout=10"
  CATCHUP_ON_START="yes"; RESTORE_CLONE_TTL=7200; PRERESTORE_KEEP=3
  # shellcheck disable=SC1090
  [ -f "$SHIVE_PLG/shive.cfg" ] && source "$SHIVE_PLG/shive.cfg"
  mkdir -p "$LOG_DIR" "$SHIVE_VAR/state" "$SHIVE_VAR/locks" "$SHIVE_VAR/restore"
}

# ---- logging ---------------------------------------------------------------
log() {
  local msg; msg="$(date '+%Y-%m-%d %H:%M:%S') [$$] $*"
  echo "$msg" >&2
  [ -n "${LOG_FILE:-}" ] && echo "$msg" >> "$LOG_FILE"
  logger -t shive -- "${SCHED:+[$SCHED] }$*"
}
die() { log "FATAL: $*"; exit 1; }

# run_cmd <cmd...>  – executes unless DRY_RUN=1 (then only logs).
run_cmd() {
  if [ "$DRY_RUN" = 1 ]; then log "DRY-RUN: $*"; return 0; fi
  log "exec: $*"
  "$@"
}

# ---- notifications ---------------------------------------------------------
# notify <normal|warning|alert> <subject> <description> [event]
notify() {
  local level="$1" subject="$2" desc="$3" event="${4:-Shive}"
  [ -x "$NOTIFY" ] || { log "notify unavailable: $subject - $desc"; return 0; }
  "$NOTIFY" -e "$event" -s "$subject" -d "$desc" -i "$level" -l "/Settings/Shive" >/dev/null 2>&1 || true
}

# ---- schedule config -------------------------------------------------------
sched_json() { $SHIVE_CLI schedule-get "$1"; }
sq() { jq -r "$2" <<<"$1"; }

# ---- run state (per schedule, JSON) ---------------------------------------
STATE_FILE=""
state_init() {
  STATE_FILE="$SHIVE_VAR/state/$1.json"
  jq -n --arg s "$1" --arg pid "$$" --arg t "$(date -Is)" \
    '{schedule:$s,pid:($pid|tonumber),started:$t,phase:"INIT",quiesced:false,resumed:true,
      containers:[],snapshot:"",sends:{},errors:[],warnings:[]}' > "$STATE_FILE"
}
state_set() { local f="$1"; shift; local tmp; tmp=$(mktemp); jq "$f" "$@" "$STATE_FILE" > "$tmp" && mv "$tmp" "$STATE_FILE"; }
state_get() { jq -r "$1" "$STATE_FILE"; }
phase() { state_set '.phase=$p' --arg p "$1"; log "phase: $1"; }
add_error()   { state_set '.errors += [$e]'   --arg e "$1"; log "ERROR: $1"; }
add_warning() { state_set '.warnings += [$w]' --arg w "$1"; log "WARN: $1"; }

# ---- ZFS helpers (local) ---------------------------------------------------
ts_now() { date '+%Y-%m-%d_%H-%M-%S'; }          # log file names
tag_now() { date '+%d%m%Y-%H%M'; }                 # snapshot tag suffix: DDMMYYYY-HHMM
# snap_name <prefix> <dataset> [recursive] -> unique snapshot name; appends -SS on a same-minute
# collision. With recursive=1 the whole tree is checked: a child may still carry a name the
# parent has already lost (non-recursive manual delete), and `zfs snapshot -r` fails on that.
snap_name() {
  local n clash=0; n="$1$(tag_now)"
  if [ "${3:-0}" = 1 ]; then zfs list -H -r -t snapshot -o name "$2" 2>/dev/null | grep -q "@$n\$" && clash=1
  else zfs list -H -o name "$2@$n" >/dev/null 2>&1 && clash=1; fi
  [ $clash = 1 ] && n="$n-$(date +%S)"
  echo "$n"
}
pool_of() { echo "${1%%/*}"; }
pool_health() { zpool list -H -o health "$1" 2>/dev/null || echo "MISSING"; }
dataset_exists() { zfs list -H -o name "$1" >/dev/null 2>&1; }
array_started() { grep -q '^mdState="STARTED"' /var/local/emhttp/var.ini 2>/dev/null; }
snap_names() { zfs list -H -o name -s creation -t snapshot -d 1 "$1" 2>/dev/null | sed 's/.*@//'; }

# ---- target abstraction ----------------------------------------------------
# spec:  local:<pool/dataset>   |   ssh://[user@]host[:port]/<pool/dataset>
t_parse() {
  local spec="$1"
  if [[ "$spec" == local:* ]]; then
    T_TYPE=local; T_DS="${spec#local:}"; T_HOST=""; T_PORT=""; T_USER=""
  elif [[ "$spec" == ssh://* ]]; then
    local rest="${spec#ssh://}"; local hostpart="${rest%%/*}"; T_DS="${rest#*/}"
    T_TYPE=ssh; T_USER="root"
    [[ "$hostpart" == *@* ]] && { T_USER="${hostpart%%@*}"; hostpart="${hostpart#*@}"; }
    T_PORT=22
    [[ "$hostpart" == *:* ]] && { T_PORT="${hostpart##*:}"; hostpart="${hostpart%%:*}"; }
    T_HOST="$hostpart"
  else
    die "invalid target spec: $spec"
  fi
  # shellcheck disable=SC2034  # T_ID is consumed by callers (bookmark names, lock names)
  T_ID=$(echo "${T_TYPE}_${T_HOST}_${T_DS}" | tr -c 'A-Za-z0-9_\n' '-' | sed 's/-\+/-/g;s/^-//;s/-$//')
}
t_ssh_cmd() {
  local key=""; [ -n "$SSH_KEY" ] && key="-i $SSH_KEY"
  echo "ssh $SSH_OPTS $key -p $T_PORT ${T_USER}@${T_HOST}"
}
t_exec() {
  if [ "$T_TYPE" = local ]; then "$@"
  else $(t_ssh_cmd) "$(printf '%q ' "$@")"; fi
}
t_dataset_exists() { t_exec zfs list -H -o name "$1" >/dev/null 2>&1; }
t_snap_names() { t_exec zfs list -H -o name -s creation -t snapshot -d 1 "$1" 2>/dev/null | sed 's/.*@//'; }
t_pool_health() {
  if [ "$T_TYPE" = local ]; then pool_health "$(pool_of "$T_DS")"
  else t_exec zpool list -H -o health "$(pool_of "$T_DS")" 2>/dev/null || echo "MISSING"; fi
}
t_reachable() { [ "$T_TYPE" = local ] && return 0; $(t_ssh_cmd) true >/dev/null 2>&1; }

# ---- locking ---------------------------------------------------------------
lock_or_exit() { exec 9>"$SHIVE_VAR/locks/$1.lock"; flock -n 9 || { log "another job holds lock '$1' - skipping"; exit 75; }; }
lock_wait() { eval "exec $2>\"$SHIVE_VAR/locks/$1.lock\""; flock -w "$3" "$2" || die "timeout waiting for lock '$1'"; }
