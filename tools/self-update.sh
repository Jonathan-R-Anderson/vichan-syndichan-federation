#!/usr/bin/env bash
#
# self-update.sh — pull the latest release from the configured git remote once a week,
# restart the app, health-check it, and roll back to the previous release on failure.
#
# Configuration comes from the repository's .env file (see .env.example):
#   UPDATE_ENABLED           1 to allow updates (anything else = dry check only)
#   UPDATE_GIT_REMOTE        the .git address to pull from (required)
#   UPDATE_GIT_BRANCH        branch to track (default: the repo's current branch)
#   UPDATE_HEALTHCHECK_URL   URL probed after restart (5xx / no-response => rollback)
#   UPDATE_RESTART_CMD       command to restart the app (default: docker compose up -d --build)
#
# Schedule it weekly with cron, e.g.:
#     0 4 * * 0  /path/to/vichan/tools/self-update.sh >> /path/to/vichan/self-update.log 2>&1
# or let the script add that entry for you:
#     tools/self-update.sh --install-cron
#
# It is safe to run more often; it only acts when the remote actually has new commits, and a
# file lock prevents overlapping runs.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_DIR" || exit 1

ENV_FILE="${UPDATE_ENV_FILE:-$REPO_DIR/.env}"
LOG_FILE="${UPDATE_LOG_FILE:-$REPO_DIR/self-update.log}"

log() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG_FILE"; }

# --- install-cron helper --------------------------------------------------------------
if [ "${1:-}" = "--install-cron" ]; then
	entry="0 4 * * 0 $REPO_DIR/tools/self-update.sh >> $LOG_FILE 2>&1"
	( crontab -l 2>/dev/null | grep -vF "$REPO_DIR/tools/self-update.sh"; echo "$entry" ) | crontab -
	echo "Installed weekly cron entry:"
	echo "  $entry"
	exit 0
fi

# --- single-instance lock -------------------------------------------------------------
exec 9>"$REPO_DIR/.self-update.lock"
if ! flock -n 9; then
	log "another self-update run is in progress; exiting"
	exit 0
fi

# --- load .env (simple KEY=VALUE lines only) ------------------------------------------
if [ ! -f "$ENV_FILE" ]; then
	log "ERROR: env file not found at $ENV_FILE"
	exit 1
fi
set -a
# shellcheck disable=SC1090
. <(grep -E '^[[:space:]]*[A-Za-z_][A-Za-z0-9_]*=' "$ENV_FILE" | sed 's/^[[:space:]]*//')
set +a

REMOTE_URL="${UPDATE_GIT_REMOTE:-}"
BRANCH="${UPDATE_GIT_BRANCH:-}"
HEALTH_URL="${UPDATE_HEALTHCHECK_URL:-}"
RESTART_CMD="${UPDATE_RESTART_CMD:-__unset__}"

if [ "${UPDATE_ENABLED:-0}" != "1" ]; then
	log "UPDATE_ENABLED is not 1; self-update disabled. Nothing to do."
	exit 0
fi
if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	log "ERROR: $REPO_DIR is not a git repository"
	exit 1
fi
if [ -z "$REMOTE_URL" ]; then
	log "ERROR: UPDATE_GIT_REMOTE is not set in $ENV_FILE; nothing to pull from"
	exit 1
fi
if [ -z "$BRANCH" ]; then
	BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo master)"
fi

# Default restart command: prefer docker compose, then docker-compose, else none.
if [ "$RESTART_CMD" = "__unset__" ]; then
	if docker compose version >/dev/null 2>&1; then
		RESTART_CMD="docker compose up -d --build"
	elif command -v docker-compose >/dev/null 2>&1; then
		RESTART_CMD="docker-compose up -d --build"
	else
		RESTART_CMD=""
	fi
fi

restart_app() {
	if [ -z "$RESTART_CMD" ]; then
		log "no restart command configured; skipping restart"
		return 0
	fi
	log "restarting app: $RESTART_CMD"
	( cd "$REPO_DIR" && eval "$RESTART_CMD" ) >>"$LOG_FILE" 2>&1
}

build_steps() {
	if [ -f composer.json ] && command -v composer >/dev/null 2>&1; then
		log "composer install --no-dev ..."
		composer install --no-dev --no-interaction --prefer-dist >>"$LOG_FILE" 2>&1 \
			|| log "WARN: composer install returned non-zero"
	fi
}

# App is considered healthy unless it returns a 5xx or fails to respond at all.
health_ok() {
	[ -n "$HEALTH_URL" ] || { log "no health-check URL set; skipping health check"; return 0; }
	local i code
	for i in 1 2 3 4 5 6; do
		code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$HEALTH_URL" 2>/dev/null || echo 000)"
		log "health check $i/6: $HEALTH_URL -> HTTP $code"
		case "$code" in
			000|5??) : ;;      # unreachable or server error: not healthy yet
			*) return 0 ;;     # any other response means the stack is serving
		esac
		sleep 5
	done
	return 1
}

roll_back() {
	local prev="$1"
	log "ROLLING BACK to previous release $prev"
	git reset --hard "$prev" >>"$LOG_FILE" 2>&1
	build_steps
	restart_app
	if health_ok; then
		log "rollback complete; app healthy at $prev"
	else
		log "CRITICAL: app still unhealthy after rollback to $prev; manual intervention required"
	fi
}

# --- update flow ----------------------------------------------------------------------
git remote set-url origin "$REMOTE_URL" 2>/dev/null || git remote add origin "$REMOTE_URL"

PREV="$(git rev-parse HEAD)"
log "current release: $PREV (tracking origin/$BRANCH at $REMOTE_URL)"

if ! git fetch --prune origin "$BRANCH" >>"$LOG_FILE" 2>&1; then
	log "ERROR: git fetch failed; leaving current release untouched"
	exit 1
fi

REMOTE_HEAD="$(git rev-parse "origin/$BRANCH" 2>/dev/null || true)"
if [ -z "$REMOTE_HEAD" ]; then
	log "ERROR: cannot resolve origin/$BRANCH; aborting without changes"
	exit 1
fi
if [ "$REMOTE_HEAD" = "$PREV" ]; then
	log "already up to date ($PREV); nothing to do"
	exit 0
fi

log "update available: $PREV -> $REMOTE_HEAD"

# Preserve any local modifications to tracked files (recoverable via `git stash list`).
if ! git diff --quiet || ! git diff --cached --quiet; then
	log "stashing local changes to tracked files before update"
	git stash push -m "self-update autostash $(date +%s)" >>"$LOG_FILE" 2>&1 || true
fi

if ! git reset --hard "$REMOTE_HEAD" >>"$LOG_FILE" 2>&1; then
	log "ERROR: failed to check out new release; rolling back"
	roll_back "$PREV"
	exit 1
fi

build_steps
restart_app

if health_ok; then
	log "SUCCESS: updated $PREV -> $REMOTE_HEAD and app is healthy"
	exit 0
fi

log "ERROR: health check failed after updating to $REMOTE_HEAD"
roll_back "$PREV"
exit 1
