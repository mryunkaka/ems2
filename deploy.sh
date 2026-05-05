#!/bin/bash

REPO="/home/fouf9972/public_html/roxwoodhospitalime"
LOG="/home/fouf9972/git-deploy.log"
GIT="/usr/bin/git"
BRANCH="main"
FETCH_RETRIES=3
FETCH_RETRY_DELAY=5

timestamp() {
  date '+%Y-%m-%d %H:%M:%S'
}

log_error() {
  echo "[$(timestamp)] ERROR: $1" >> "$LOG"
}

run_git() {
  GIT_HTTP_VERSION=HTTP/1.1 "$GIT" "$@"
}

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

FETCH_OUT=""
FETCH_STATUS=1
ATTEMPT=1
while [ $ATTEMPT -le $FETCH_RETRIES ]; do
  FETCH_OUT=$(run_git fetch origin "$BRANCH" 2>&1)
  FETCH_STATUS=$?

  if [ $FETCH_STATUS -eq 0 ]; then
    break
  fi

  if [ $ATTEMPT -lt $FETCH_RETRIES ]; then
    log_error "git fetch gagal percobaan $ATTEMPT/$FETCH_RETRIES - $FETCH_OUT"
    sleep "$FETCH_RETRY_DELAY"
  fi

  ATTEMPT=$((ATTEMPT + 1))
done

if [ $FETCH_STATUS -ne 0 ]; then
  log_error "git fetch gagal setelah $FETCH_RETRIES percobaan - $FETCH_OUT"
  exit 1
fi

REMOTE=$(run_git rev-parse "origin/$BRANCH")
REMOTE_STATUS=$?
if [ $REMOTE_STATUS -ne 0 ] || [ -z "$REMOTE" ]; then
  log_error "gagal baca origin/$BRANCH - $REMOTE"
  exit 1
fi

if [ "$OLD" = "$REMOTE" ]; then
  exit 0
fi

CHECKOUT_OUT=$(run_git checkout -B "$BRANCH" "origin/$BRANCH" 2>&1)
CHECKOUT_STATUS=$?
if [ $CHECKOUT_STATUS -ne 0 ]; then
  log_error "checkout gagal - $CHECKOUT_OUT"
  exit 1
fi

RESET_OUT=$(run_git reset --hard "origin/$BRANCH" 2>&1)
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

echo "[$(timestamp)] Deploy baru: $LATEST_COMMIT" >> "$LOG"

exit 0
