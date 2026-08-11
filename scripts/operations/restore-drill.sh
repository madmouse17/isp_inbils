#!/usr/bin/env bash
# Backup + restore into isolated inbils_restore_drill_* DB.
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
EOF
}

DATABASE=""
OUTPUT_DIR=""
DRY_RUN=0
SKIP_RESTORE=0

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

TS="$(date -u +%Y%m%dT%H%M%SZ)"
DRILL_DB="inbils_restore_drill_${TS}"

BACKUP_ARGS=(--database "${DATABASE}" --output-dir "${OUTPUT_DIR}")
RESTORE_EXTRA=()
if [[ "${DRY_RUN}" -eq 1 ]]; then
  BACKUP_ARGS+=(--dry-run)
  RESTORE_EXTRA+=(--dry-run)
fi

info "restore-drill source=${DATABASE} isolated_target=${DRILL_DB}"
bash "${SCRIPT_DIR}/backup.sh" "${BACKUP_ARGS[@]}"

if [[ "${SKIP_RESTORE}" -eq 1 ]]; then
  info "skip-restore set"
  echo "EXTERNAL_EVIDENCE_REQUIRED"
  exit 0
fi

# Find newest dump under output dir for this source.
DUMP="$(find "${OUTPUT_DIR}" -type f -name "${DATABASE}.sql" 2>/dev/null | sort | tail -n 1 || true)"
if [[ -z "${DUMP}" && "${DRY_RUN}" -eq 1 ]]; then
  DUMP="${OUTPUT_DIR}/dry-run/${DATABASE}.sql"
  info "dry-run placeholder dump path ${DUMP}"
fi
[[ -n "${DUMP}" || "${DRY_RUN}" -eq 1 ]] || die "no dump found under ${OUTPUT_DIR}"

if [[ "${DRY_RUN}" -eq 1 ]]; then
  bash "${SCRIPT_DIR}/restore.sh" --input "${DUMP}" --database "${DRILL_DB}" --create-database "${RESTORE_EXTRA[@]}"
else
  bash "${SCRIPT_DIR}/restore.sh" --input "${DUMP}" --database "${DRILL_DB}" --create-database
fi

info "drill DB: ${DRILL_DB}"
info "fill docs/operations/RESTORE_DRILL_TEMPLATE.md"
echo "EXTERNAL_EVIDENCE_REQUIRED"
