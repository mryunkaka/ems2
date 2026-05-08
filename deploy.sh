#!/bin/bash

REPO="/home/fouf9972/public_html/roxwoodhospitalime"
LOG="/home/fouf9972/git-deploy.log"
GIT="/usr/bin/git"
BRANCH="main"
REMOTE_NAME="${DEPLOY_REMOTE:-origin}"
FALLBACK_REMOTES="${DEPLOY_FALLBACK_REMOTES:-github-ssh github-mirror}"
FETCH_RETRIES=3
FETCH_RETRY_DELAY=5
FAILURE_COOLDOWN_SECONDS=900
LOCK_FILE="/home/fouf9972/.deploy.lock"
FAILURE_STATE_FILE="/home/fouf9972/.deploy-last-failure"

timestamp() {
  date '+%Y-%m-%d %H:%M:%S'
}

epoch_now() {
  date '+%s'
}

log_info() {
  echo "[$(timestamp)] $1" >> "$LOG"
}

log_error() {
  echo "[$(timestamp)] ERROR: $1" >> "$LOG"
}

run_git() {
  "$GIT" -c http.version=HTTP/1.1 -c http.lowSpeedLimit=1000 -c http.lowSpeedTime=30 "$@"
}

read_failure_state() {
  if [ -f "$FAILURE_STATE_FILE" ]; then
    IFS='|' read -r LAST_FAILURE_TS LAST_FAILURE_KEY < "$FAILURE_STATE_FILE"
  else
    LAST_FAILURE_TS=0
    LAST_FAILURE_KEY=""
  fi
}

remember_failure() {
  printf '%s|%s\n' "$(epoch_now)" "$1" > "$FAILURE_STATE_FILE"
}

clear_failure_state() {
  rm -f "$FAILURE_STATE_FILE"
}

should_log_failure() {
  FAILURE_KEY="$1"
  read_failure_state

  NOW_TS=$(epoch_now)
  if [ "$LAST_FAILURE_KEY" = "$FAILURE_KEY" ] && [ $((NOW_TS - LAST_FAILURE_TS)) -lt "$FAILURE_COOLDOWN_SECONDS" ]; then
    return 1
  fi

  remember_failure "$FAILURE_KEY"
  return 0
}

detect_host_from_remote_url() {
  REMOTE_URL="$1"

  case "$REMOTE_URL" in
    git@*:* )
      printf '%s\n' "$REMOTE_URL" | sed -E 's#^[^@]+@([^:]+):.*#\1#'
      ;;
    ssh://* | http://* | https://* )
      printf '%s\n' "$REMOTE_URL" | sed -E 's#^[a-z]+://([^/@]+@)?([^/:]+).*#\2#'
      ;;
    * )
      printf '%s\n' ""
      ;;
  esac
}

host_resolves() {
  TARGET_HOST="$1"

  if [ -z "$TARGET_HOST" ]; then
    return 0
  fi

  if command -v getent >/dev/null 2>&1; then
    getent hosts "$TARGET_HOST" >/dev/null 2>&1
    return $?
  fi

  if command -v host >/dev/null 2>&1; then
    host "$TARGET_HOST" >/dev/null 2>&1
    return $?
  fi

  if command -v nslookup >/dev/null 2>&1; then
    nslookup "$TARGET_HOST" >/dev/null 2>&1
    return $?
  fi

  return 0
}

remote_exists() {
  run_git remote get-url "$1" >/dev/null 2>&1
}

fetch_remote_branch() {
  FETCH_REMOTE="$1"
  FETCH_OUT=""
  FETCH_STATUS=1
  ATTEMPT=1

  while [ $ATTEMPT -le $FETCH_RETRIES ]; do
    FETCH_OUT=$(run_git fetch --prune "$FETCH_REMOTE" "$BRANCH" 2>&1)
    FETCH_STATUS=$?

    if [ $FETCH_STATUS -eq 0 ]; then
      FETCH_RESULT="$FETCH_OUT"
      return 0
    fi

    if [ $ATTEMPT -lt $FETCH_RETRIES ]; then
      sleep "$FETCH_RETRY_DELAY"
    fi

    ATTEMPT=$((ATTEMPT + 1))
  done

  FETCH_RESULT="$FETCH_OUT"
  return 1
}

acquire_lock() {
  exec 9>"$LOCK_FILE" || exit 1
  if ! flock -n 9; then
    exit 0
  fi
}

acquire_lock

cd "$REPO" || {
  log_error "gagal masuk repo $REPO"
  exit 1
}

if [ ! -d ".git" ]; then
  log_error "folder .git tidak ditemukan di $REPO"
  exit 1
fi

if [ ! -x "$GIT" ]; then
  log_error "git tidak ditemukan atau tidak executable di $GIT"
  exit 1
fi

OLD=$(run_git rev-parse HEAD)
OLD_STATUS=$?
if [ $OLD_STATUS -ne 0 ] || [ -z "$OLD" ]; then
  log_error "gagal baca HEAD - $OLD"
  exit 1
fi

REMOTE_CANDIDATES="$REMOTE_NAME $FALLBACK_REMOTES"
ACTIVE_REMOTE=""
LAST_FETCH_ERROR=""

for CANDIDATE_REMOTE in $REMOTE_CANDIDATES; do
  if ! remote_exists "$CANDIDATE_REMOTE"; then
    continue
  fi

  REMOTE_URL=$(run_git remote get-url "$CANDIDATE_REMOTE" 2>/dev/null)
  REMOTE_HOST=$(detect_host_from_remote_url "$REMOTE_URL")

  if ! host_resolves "$REMOTE_HOST"; then
    LAST_FETCH_ERROR="remote $CANDIDATE_REMOTE tidak bisa resolve host $REMOTE_HOST"
    continue
  fi

  if fetch_remote_branch "$CANDIDATE_REMOTE"; then
    ACTIVE_REMOTE="$CANDIDATE_REMOTE"
    LAST_FETCH_ERROR=""
    break
  fi

  LAST_FETCH_ERROR="remote $CANDIDATE_REMOTE fetch gagal - $FETCH_RESULT"
done

if [ -z "$ACTIVE_REMOTE" ]; then
  if should_log_failure "fetch:${LAST_FETCH_ERROR}"; then
    log_error "git fetch gagal - $LAST_FETCH_ERROR"
  fi
  exit 1
fi

REMOTE=$(run_git rev-parse "$ACTIVE_REMOTE/$BRANCH")
REMOTE_STATUS=$?
if [ $REMOTE_STATUS -ne 0 ] || [ -z "$REMOTE" ]; then
  if should_log_failure "revparse:${ACTIVE_REMOTE}"; then
    log_error "gagal baca $ACTIVE_REMOTE/$BRANCH - $REMOTE"
  fi
  exit 1
fi

if [ "$OLD" = "$REMOTE" ]; then
  clear_failure_state
  exit 0
fi

CHECKOUT_OUT=$(run_git checkout -B "$BRANCH" "$ACTIVE_REMOTE/$BRANCH" 2>&1)
CHECKOUT_STATUS=$?
if [ $CHECKOUT_STATUS -ne 0 ]; then
  log_error "checkout gagal - $CHECKOUT_OUT"
  exit 1
fi

RESET_OUT=$(run_git reset --hard "$ACTIVE_REMOTE/$BRANCH" 2>&1)
RESET_STATUS=$?
if [ $RESET_STATUS -ne 0 ]; then
  log_error "reset gagal - $RESET_OUT"
  exit 1
fi

LATEST_COMMIT=$(run_git log -1 --pretty=format:"%h | %an | %s" "$REMOTE")
LATEST_STATUS=$?
if [ $LATEST_STATUS -ne 0 ] || [ -z "$LATEST_COMMIT" ]; then
  log_error "gagal baca detail commit terbaru - $LATEST_COMMIT"
  exit 1
fi

clear_failure_state

if [ "$ACTIVE_REMOTE" = "$REMOTE_NAME" ]; then
  log_info "Deploy baru: $LATEST_COMMIT"
else
  log_info "Deploy baru [$ACTIVE_REMOTE]: $LATEST_COMMIT"
fi

exit 0
