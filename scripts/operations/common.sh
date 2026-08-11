#!/usr/bin/env bash
# Shared helpers for scripts/operations/*.sh (git-bash / bash).
# shellcheck shell=bash

set -euo pipefail

OPS_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${OPS_ROOT}/../.." && pwd)"

# Protected DB names (restore refuses unless dual flags).
# Includes live app DBs and shared test schemas that must not be clobbered by drills.
PROTECTED_DB_NAMES_REGEX='^(inbils|inbils_testing|inbils_e2e|inbils_production|production|prod|mysql|information_schema|performance_schema|sys)$'

die() {
  echo "ERROR: $*" >&2
  exit 1
}

info() {
  echo "INFO: $*"
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "missing command: $1"
}

# Prefer defaults-extra-file; never echo password.
mysql_client_args() {
  local args=()
  if [[ -n "${MYSQL_DEFAULTS_EXTRA_FILE:-}" ]]; then
    [[ -f "${MYSQL_DEFAULTS_EXTRA_FILE}" ]] || die "MYSQL_DEFAULTS_EXTRA_FILE not a file: ${MYSQL_DEFAULTS_EXTRA_FILE}"
    args+=(--defaults-extra-file="${MYSQL_DEFAULTS_EXTRA_FILE}")
  else
    [[ -n "${MYSQL_HOST:-}" ]] && args+=(-h "${MYSQL_HOST}")
    [[ -n "${MYSQL_PORT:-}" ]] && args+=(-P "${MYSQL_PORT}")
    [[ -n "${MYSQL_USER:-}" ]] && args+=(-u "${MYSQL_USER}")
    # MYSQL_PWD is read by mysql client from env (not argv). Prefer defaults-extra-file.
    if [[ -n "${MYSQL_PASSWORD:-}" ]]; then
      export MYSQL_PWD="${MYSQL_PASSWORD}"
    fi
  fi
  printf '%s\n' "${args[@]}"
}

resolve_mysql_bin() {
  if [[ -n "${MYSQL_BIN:-}" ]]; then
    echo "${MYSQL_BIN}"
    return
  fi
  local candidates=(
    "/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql"
    "/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe"
    "mysql"
  )
  local c
  for c in "${candidates[@]}"; do
    if command -v "$c" >/dev/null 2>&1 || [[ -x "$c" ]]; then
      echo "$c"
      return
    fi
  done
  die "mysql client not found; set MYSQL_BIN"
}

resolve_mysqldump_bin() {
  if [[ -n "${MYSQL_DUMP_BIN:-}" ]]; then
    echo "${MYSQL_DUMP_BIN}"
    return
  fi
  local candidates=(
    "/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump"
    "/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe"
    "mysqldump"
  )
  local c
  for c in "${candidates[@]}"; do
    if command -v "$c" >/dev/null 2>&1 || [[ -x "$c" ]]; then
      echo "$c"
      return
    fi
  done
  die "mysqldump not found; set MYSQL_DUMP_BIN"
}

is_blank() {
  [[ -z "${1//[[:space:]]/}" ]]
}

is_protected_db() {
  local name="$1"
  [[ "${name}" =~ ${PROTECTED_DB_NAMES_REGEX} ]]
}

refuse_blank_target() {
  local label="$1"
  local value="${2:-}"
  if is_blank "${value}"; then
    die "refuse blank ${label}: set explicit value (no default production target)"
  fi
  case "${value}" in
    default|DEFAULT|null|NULL|none|NONE|.|/)
      die "refuse default/placeholder ${label}: '${value}'"
      ;;
  esac
}

sha256_file() {
  local f="$1"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "${f}" | awk '{print $1}'
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "${f}" | awk '{print $1}'
  else
    die "need sha256sum or shasum"
  fi
}

maybe_encrypt() {
  local src="$1"
  if [[ -z "${BACKUP_ENCRYPTION_KEY_FILE:-}" ]]; then
    echo "${src}"
    return
  fi
  [[ -f "${BACKUP_ENCRYPTION_KEY_FILE}" ]] || die "BACKUP_ENCRYPTION_KEY_FILE missing: ${BACKUP_ENCRYPTION_KEY_FILE}"
  require_cmd openssl
  local dest="${src}.enc"
  openssl enc -aes-256-cbc -salt -pbkdf2 \
    -in "${src}" \
    -out "${dest}" \
    -pass "file:${BACKUP_ENCRYPTION_KEY_FILE}"
  echo "${dest}"
}
