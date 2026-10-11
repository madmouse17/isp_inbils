#!/usr/bin/env bash
# Restore MySQL dump into explicit target. Prefers isolated drill DBs.
# shellcheck shell=bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

usage() {
  cat <<'EOF'
Usage: restore.sh --input FILE --database NAME [options]

Required:
  --input FILE          .sql dump (or .sql.enc if decrypting)
  --database NAME       Target database (prefer inbils_restore_drill_*)

Optional:
  --checksum-file FILE  SHA-256 manifest (defaults to ${INPUT}.sha256)
  --create-database     CREATE DATABASE IF NOT EXISTS target
  --i-understand-production-restore
                        Acknowledge non-drill restore
  --allow-protected     Allow protected production-like DB names (requires above)
  --dry-run             Print actions only
  -h, --help

Env: MYSQL_DEFAULTS_EXTRA_FILE | MYSQL_HOST/PORT/USER/PASSWORD
     MYSQL_BIN, BACKUP_ENCRYPTION_KEY_FILE (for .enc inputs)
     OPS_ALERT_HOOK     Shell command invoked as: <hook> <event> <message>
                        on any failure; secrets stay in the operator env.

Refuses blank targets. Refuses protected names unless both production flags set.
Verifies the matching SHA-256 manifest before applying any dump; fails closed.
Never default-overwrites production.
EOF
}

INPUT=""
DATABASE=""
CHECKSUM_FILE=""
CREATE_DB=0
UNDERSTAND=0
ALLOW_PROTECTED=0
DRY_RUN=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --input) INPUT="${2:-}"; shift 2 ;;
    --database) DATABASE="${2:-}"; shift 2 ;;
    --checksum-file) CHECKSUM_FILE="${2:-}"; shift 2 ;;
    --create-database) CREATE_DB=1; shift ;;
    --i-understand-production-restore) UNDERSTAND=1; shift ;;
    --allow-protected) ALLOW_PROTECTED=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown arg: $1" ;;
  esac
done

refuse_blank_target "input" "${INPUT}"
refuse_blank_target "database" "${DATABASE}"

if is_protected_db "${DATABASE}"; then
  if [[ "${UNDERSTAND}" -ne 1 || "${ALLOW_PROTECTED}" -ne 1 ]]; then
    die "refuse protected database name '${DATABASE}': need --i-understand-production-restore AND --allow-protected (prefer inbils_restore_drill_*)"
  fi
  info "WARNING: protected target allowed by dual flags: ${DATABASE}"
fi

if [[ ! "${DATABASE}" =~ ^inbils_restore_drill_ ]] && [[ "${UNDERSTAND}" -ne 1 ]]; then
  die "non-drill target '${DATABASE}' requires --i-understand-production-restore (prefer inbils_restore_drill_*)"
fi

# Determine the matching SHA-256 manifest for the input artifact.
if [[ -z "${CHECKSUM_FILE}" ]]; then
  CHECKSUM_FILE="${INPUT}.sha256"
fi

SQL_FILE="${INPUT}"
if [[ "${INPUT}" == *.enc ]]; then
  [[ -n "${BACKUP_ENCRYPTION_KEY_FILE:-}" ]] || die "encrypted input needs BACKUP_ENCRYPTION_KEY_FILE"
  require_cmd openssl
  SQL_FILE="${INPUT%.enc}.decrypted.sql"
fi

if [[ "${DRY_RUN}" -eq 1 ]]; then
  info "dry-run: would verify ${INPUT} against ${CHECKSUM_FILE}, restore -> database ${DATABASE}"
  exit 0
fi

# The input artifact (either the .sql or the .enc) must exist before verification.
[[ -f "${INPUT}" ]] || die "input not found: ${INPUT}"

# Fail closed: verify the SHA-256 manifest BEFORE applying any dump.
verify_sha256_manifest "${INPUT}" "${CHECKSUM_FILE}"

# Decrypt after integrity verification (only for .enc inputs).
if [[ "${INPUT}" == *.enc ]]; then
  openssl enc -d -aes-256-cbc -pbkdf2 \
    -in "${INPUT}" \
    -out "${SQL_FILE}" \
    -pass "file:${BACKUP_ENCRYPTION_KEY_FILE}"
fi

MYSQL_BIN="$(resolve_mysql_bin)"
mapfile -t CLIENT_ARGS < <(mysql_client_args)

if [[ "${CREATE_DB}" -eq 1 ]]; then
  info "creating database if not exists: ${DATABASE}"
  if ! "${MYSQL_BIN}" "${CLIENT_ARGS[@]}" -e "CREATE DATABASE IF NOT EXISTS \`${DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"; then
    rc=$?
    alert_hook "restore_create_db_failed" "CREATE DATABASE ${DATABASE} failed (rc=${rc})" || true
    die "CREATE DATABASE ${DATABASE} failed (rc=${rc})"
  fi
fi

info "restoring into ${DATABASE}"
if ! "${MYSQL_BIN}" "${CLIENT_ARGS[@]}" "${DATABASE}" < "${SQL_FILE}"; then
  rc=$?
  alert_hook "restore_failed" "restore into ${DATABASE} failed (rc=${rc})" || true
  die "restore into ${DATABASE} failed (rc=${rc})"
fi

# Clean up the transient decrypted file, if any.
if [[ "${INPUT}" == *.enc && -f "${SQL_FILE}" ]]; then
  rm -f "${SQL_FILE}"
fi

info "restore finished target=${DATABASE}"
info "production success not claimed; record drill template; off-host: EXTERNAL_EVIDENCE_REQUIRED"
echo "EXTERNAL_EVIDENCE_REQUIRED"
