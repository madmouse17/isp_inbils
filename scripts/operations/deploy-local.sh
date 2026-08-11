#!/usr/bin/env bash
# Local non-prod deploy rehearsal. No migrate:fresh/seed. Migrate is document-only by default.
# shellcheck shell=bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

usage() {
  cat <<'EOF'
Usage: deploy-local.sh [options]

Local non-production rehearsal only.

Options:
  --with-composer     Run composer install
  --with-npm          Run npm ci && npm run build
  --optimize          Run artisan optimize / config:cache
  --run-migrate       Actually run php artisan migrate (still never migrate:fresh)
  --dry-run           Print steps only
  -h, --help

Default: schedule:list + document migrate steps + remind health-check.
Never runs migrate:fresh or db:seed.
EOF
}

WITH_COMPOSER=0
WITH_NPM=0
OPTIMIZE=0
RUN_MIGRATE=0
DRY_RUN=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --with-composer) WITH_COMPOSER=1; shift ;;
    --with-npm) WITH_NPM=1; shift ;;
    --optimize) OPTIMIZE=1; shift ;;
    --run-migrate) RUN_MIGRATE=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown arg: $1" ;;
  esac
done

cd "${REPO_ROOT}"

run() {
  if [[ "${DRY_RUN}" -eq 1 ]]; then
    info "dry-run: $*"
  else
    info "run: $*"
    "$@"
  fi
}

info "deploy-local rehearsal root=${REPO_ROOT}"

if [[ "${WITH_COMPOSER}" -eq 1 ]]; then
  require_cmd composer
  run composer install
fi

if [[ "${WITH_NPM}" -eq 1 ]]; then
  require_cmd npm
  run npm ci
  run npm run build
fi

if [[ "${OPTIMIZE}" -eq 1 ]]; then
  run php artisan config:clear
  run php artisan optimize
fi

run php artisan schedule:list

info "migrate: document-only by default — review migrations then optionally --run-migrate"
info "forbidden in this script: migrate:fresh migrate:refresh db:seed"

if [[ "${RUN_MIGRATE}" -eq 1 ]]; then
  run php artisan migrate
fi

info "next: bash scripts/operations/health-check.sh --base-url <local>"
info "deploy-local complete"
