#!/bin/bash
# shive guard: docker – stop/restart containers whose bind-mounts live on the
# schedule's target datasets. Expects $SCHED and state_* from common.sh.
#
# Contract (relied upon by shive-run / shive-recover):
#   guard_prepare  -> writes .containers[] = [{name,running}] into state
#   guard_quiesce  -> sets .quiesced=true/.resumed=false BEFORE the first stop, stops running ones
#   guard_resume   -> starts everything that was running; idempotent; returns 1 on any failure

guard_prepare() {
  local list
  list=$($SHIVE_CLI linked "$SCHED") || die "container discovery failed"
  state_set '.containers=$c' --argjson c "$list"
  log "linked containers: $(jq -r 'length' <<<"$list") (running: $(jq '[.[]|select(.running)]|length' <<<"$list")): $(jq -r '[.[]|.name]|join(", ")' <<<"$list")"
}

guard_quiesce() {
  local running; running=$(state_get '[.containers[]|select(.running)|.name]|.[]')
  [ -z "$running" ] && { log "no running linked containers - nothing to stop"; return 0; }
  state_set '.quiesced=true | .resumed=false'   # crash after this point => recover will resume
  local c fail=0
  for c in $running; do
    if [ "$DRY_RUN" = 1 ]; then
      log "DRY-RUN: docker stop -t ${DOCKER_STOP_TIMEOUT:-60} $c"
      state_set '(.containers[]|select(.name==$n)).stopped=true' --arg n "$c"
      continue
    fi
    log "exec: docker stop -t ${DOCKER_STOP_TIMEOUT:-60} $c"
    # Run in the background and `wait`, rather than as a plain foreground command: bash defers
    # a trapped signal until the current foreground command returns, so a long-running
    # docker-stop timeout (a container that ignores SIGTERM) would otherwise swallow the
    # SIGTERM `stopping_svcs` sends on array stop until it's too late - its own SIGKILL
    # escalation (unblockable, no trap possible) can then fire first. `wait` on a background
    # job, unlike a synchronous foreground command, IS interrupted immediately by an incoming
    # trapped signal (bash(1), "wait" - documented behaviour), which is exactly what we need.
    # 9>&- closes the inherited schedule-lock fd in the child: a backgrounded process inherits
    # every open descriptor, so if this run is killed while docker stop is still in flight, the
    # orphaned child would keep the flock held until it finally exits - and the next scheduled
    # run would bounce off it with "another job holds lock" and silently do nothing.
    docker stop -t "${DOCKER_STOP_TIMEOUT:-60}" "$c" >/dev/null 9>&- &
    if wait $!; then
      state_set '(.containers[]|select(.name==$n)).stopped=true' --arg n "$c"
    else
      add_error "container '$c' failed to stop"; fail=1
    fi
  done
  return $fail
}

guard_resume() {
  [ "$(state_get '.quiesced')" = true ] || return 0
  [ "$(state_get '.resumed')"  = true ] && return 0
  local c fail=0 failed=()
  for c in $(state_get '[.containers[]|select(.running)|.name]|.[]'); do
    if [ "$DRY_RUN" = 1 ]; then log "DRY-RUN: docker start $c"; continue; fi
    local i
    for i in 1 2 3; do docker start "$c" >/dev/null 2>&1 && break; sleep $((i*5)); done
    if docker inspect -f '{{.State.Running}}' "$c" 2>/dev/null | grep -q true; then
      log "resumed container $c"
    else
      failed+=("$c"); fail=1
    fi
  done
  if [ $fail = 0 ]; then
    state_set '.resumed=true'
  else
    state_set '.resume_failed=$f' --argjson f "$(printf '%s\n' "${failed[@]}" | jq -R . | jq -s .)"
    add_error "container resume FAILED: ${failed[*]}"
  fi
  return $fail
}
