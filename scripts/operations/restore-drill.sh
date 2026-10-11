#!/usr/bin/env bash
# Backup + restore into isolated inbils_restore_drill_* DB.
# Guarantees cleanup of its disposable drill schema/artifacts via safe trap.
# shellcheck shell=bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

usage() {
  cat <<'EOF'
Usage: restore-drill.sh --database SOURCE --output-dir DIR [options]

Runs backup.sh then restore.sh into inbils_restore_drill_<timestamp>.
Does not touch production. Always prints EXTERNAL_EVIDENCE_REQUIRED for off-host.

Optional:
  --dry-run
  --skip-restore   Only backup
  -h, --help

Guarantees cleanup of the disposable drill schema on exit (success or failure).
Refuses to run if a drill schema with the same timestamp already exists.
EOF
}

DATABASE=""
OUTPUT_DIR=""
DRY_RUN=0
SKIP_RESTORE=0
DRILL_DB=""
CLEANUP_DONE=0

cleanup_drill() {
  # Trap handler: drop the disposable drill schema, remove dump artifacts.
  if [[ "${CLEANUP_DONE}" -eq 1 ]]; then
    return
  fi
  CLEANUP_DONE=1
  if [[ -z "${DRILL_DB}" ]] || [[ "${DRY_RUN}" -eq 1 ]]; then
    return
  fi
  # Only drop drill-named schemas; never touch anything else.
  if [[ "${DRILL_DB}" =~ ^inbils_restore_drill_ ]]; then
    info "cleanup: dropping drill schema ${DRILL_DB}"
    MYSQL_BIN="$(resolve_mysql_bin)"
    mapfile -t CLIENT_ARGS < <(mysql_client_args)
    "${MYSQL_BIN}" "${CLIENT_ARGS[@]}" -e "DROP DATABASE IF EXISTS \`${DRILL_DB}\`;" 2>/dev/null || \
      info "cleanup: schema drop skipped (may not exist)"
  fi
  # Clean up the dump artifact directory for this drill run.
  if [[ -n "${STAMP_DIR:-}" && -d "${STAMP_DIR}" ]]; then
    info "cleanup: removing dump artifacts ${STAMP_DIR}"
    rm -rf "${STAMP_DIR}"
  fi
  info "cleanup: drill artifacts removed"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --database) DATABASE="${2:-}"; shift 2 ;;
    --output-dir) OUTPUT_DIR="${2:-}"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    --skip-restore) SKIP_RESTORE=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown arg: $1" ;;
  esac
done

refuse_blank_target "database" "${DATABASE}"
refuse_blank_target "output-dir" "${OUTPUT_DIR}"

# The source is only ever dumped read-only (mysqldump); the safe-target guard
# applies to the isolated drill target below, never the source.
TS="$(date -u +%Y%m%dT%H%M%SZ)"
DRILL_DB="inbils_restore_drill_${TS}"

# Pre-refusal: refuse if drill schema already exists (idempotency guard).
if [[ "${DRY_RUN}" -eq 0 ]]; then
  MYSQL_BIN="$(resolve_mysql_bin)"
  mapfile -t CLIENT_ARGS < <(mysql_client_args)
  if "${MYSQL_BIN}" "${CLIENT_ARGS[@]}" -e "SELECT 1 FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='${DRILL_DB}'" 2>/dev/null | grep -q 1; then
    die "drill schema '${DRILL_DB}' already exists; aborting"
  fi
fi

# Trap handles cleanup on EXIT, ERR, INT, TERM.
trap cleanup_drill EXIT
trap cleanup_drill INT TERM

BACKUP_ARGS=(--database "${DATABASE}" --output-dir "${OUTPUT_DIR}")
RESTORE_EXTRA=()
if [[ "${DRY_RUN}" -eq 1 ]]; then
  BACKUP_ARGS+=(--dry-run)
  RESTORE_EXTRA+=(--dry-run)
fi

info "restore-drill source=${DATABASE} isolated_target=${DRILL_DB}"

# Step 1: backup.
if ! bash "${SCRIPT_DIR}/backup.sh" "${BACKUP_ARGS[@]}"; then
  alert_hook "drill_backup_failed" "restore-drill backup failed for ${DATABASE}" || true
  die "restore-drill: backup.sh failed"
fi

if [[ "${SKIP_RESTORE}" -eq 1 ]]; then
  info "skip-restore set"
  echo "EXTERNAL_EVIDENCE_REQUIRED"
  exit 0
fi

# Find the dump artifact directory for the SHA-256 manifest.
STAMP_DIR="$(find "${OUTPUT_DIR}" -maxdepth 1 -type d -name "inbils-backup-${DATABASE}-*" 2>/dev/null | sort | tail -n 1 || true)"
if [[ -z "${STAMP_DIR}" && "${DRY_RUN}" -eq 1 ]]; then
  STAMP_DIR="${OUTPUT_DIR}/dry-run-placeholder"
  info "dry-run: placeholder stamp-dir ${STAMP_DIR}"
fi

# Find newest dump under output dir for this source.
DUMP="$(find "${OUTPUT_DIR}" -type f -name "${DATABASE}.sql" 2>/dev/null | sort | tail -n 1 || true)"
if [[ -z "${DUMP}" && "${DRY_RUN}" -eq 1 ]]; then
  DUMP="${OUTPUT_DIR}/dry-run/${DATABASE}.sql"
  info "dry-run placeholder dump path ${DUMP}"
fi
[[ -n "${DUMP}" || "${DRY_RUN}" -eq 1 ]] || die "no dump found under ${OUTPUT_DIR}"

# Determine the matching SHA-256 manifest.
SHA256_FILE="${DUMP}.sha256"
if [[ -n "${STAMP_DIR}" && -d "${STAMP_DIR}" ]]; then
  SHA256_FILE="$(find "${STAMP_DIR}" -maxdepth 1 -name "${DATABASE}.sql.sha256" 2>/dev/null | head -n 1 || echo "${SHA256_FILE}")"
fi

if [[ "${DRY_RUN}" -eq 1 ]]; then
  bash "${SCRIPT_DIR}/restore.sh" --input "${DUMP}" --database "${DRILL_DB}" --create-database --checksum-file "${SHA256_FILE}" "${RESTORE_EXTRA[@]}"
else
  bash "${SCRIPT_DIR}/restore.sh" --input "${DUMP}" --database "${DRILL_DB}" --create-database --checksum-file "${SHA256_FILE}"
fi

# If we get here, restore succeeded. Cleanup trap handles the schema drop.
info "drill DB: ${DRILL_DB}"
info "fill docs/operations/RESTORE_DRILL_TEMPLATE.md"
echo "EXTERNAL_EVIDENCE_REQUIRED"