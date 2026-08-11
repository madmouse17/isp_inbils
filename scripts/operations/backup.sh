#!/usr/bin/env bash
# MySQL logical backup + optional media tarball. Non-production defaults only.
# shellcheck shell=bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

usage() {
  cat <<'EOF'
Usage: backup.sh --database NAME --output-dir DIR [options]

Required:
  --database NAME       Source MySQL database name
  --output-dir DIR      Directory for dump artifacts (created if missing)

Optional:
  --media-dir PATH      If set, tar media into output dir
  --dry-run             Print actions only
  -h, --help            This help

Env:
  MYSQL_DEFAULTS_EXTRA_FILE  Preferred credentials file (mode 600)
  MYSQL_HOST MYSQL_PORT MYSQL_USER MYSQL_PASSWORD
  MYSQL_DUMP_BIN             Override mysqldump path
  BACKUP_ENCRYPTION_KEY_FILE Optional openssl enc key file

Never logs passwords. Prefer defaults-extra-file over MYSQL_PASSWORD.
EOF
}

DATABASE=""
OUTPUT_DIR=""
MEDIA_DIR=""
DRY_RUN=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --database) DATABASE="${2:-}"; shift 2 ;;
    --output-dir) OUTPUT_DIR="${2:-}"; shift 2 ;;
    --media-dir) MEDIA_DIR="${2:-}"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown arg: $1" ;;
  esac
done

refuse_blank_target "database" "${DATABASE}"
refuse_blank_target "output-dir" "${OUTPUT_DIR}"

TS="$(date -u +%Y%m%dT%H%M%SZ)"
STAMP_DIR="${OUTPUT_DIR%/}/inbils-backup-${DATABASE}-${TS}"
DUMP_FILE="${STAMP_DIR}/${DATABASE}.sql"

if [[ "${DRY_RUN}" -eq 1 ]]; then
  info "dry-run: would mkdir ${STAMP_DIR}"
  info "dry-run: would mysqldump database=${DATABASE} -> ${DUMP_FILE}"
  [[ -n "${MEDIA_DIR}" ]] && info "dry-run: would tar media ${MEDIA_DIR}"
  [[ -n "${BACKUP_ENCRYPTION_KEY_FILE:-}" ]] && info "dry-run: would openssl enc with key file"
  exit 0
fi

DUMP_BIN="$(resolve_mysqldump_bin)"
mkdir -p "${STAMP_DIR}"

mapfile -t CLIENT_ARGS < <(mysql_client_args)

info "dumping ${DATABASE} with ${DUMP_BIN}"
"${DUMP_BIN}" "${CLIENT_ARGS[@]}" \
  --single-transaction \
  --routines \
  --triggers \
  --databases "${DATABASE}" \
  > "${DUMP_FILE}"

HASH="$(sha256_file "${DUMP_FILE}")"
echo "${HASH}  ${DATABASE}.sql" > "${DUMP_FILE}.sha256"
info "sha256 ${HASH}"

FINAL_DUMP="$(maybe_encrypt "${DUMP_FILE}")"
if [[ "${FINAL_DUMP}" != "${DUMP_FILE}" ]]; then
  info "encrypted -> ${FINAL_DUMP}"
  ENC_HASH="$(sha256_file "${FINAL_DUMP}")"
  echo "${ENC_HASH}  $(basename "${FINAL_DUMP}")" > "${FINAL_DUMP}.sha256"
fi

if [[ -n "${MEDIA_DIR}" ]]; then
  [[ -d "${MEDIA_DIR}" ]] || die "media-dir not a directory: ${MEDIA_DIR}"
  MEDIA_TAR="${STAMP_DIR}/media.tar.gz"
  tar -czf "${MEDIA_TAR}" -C "$(dirname "${MEDIA_DIR}")" "$(basename "${MEDIA_DIR}")"
  MH="$(sha256_file "${MEDIA_TAR}")"
  echo "${MH}  media.tar.gz" > "${MEDIA_TAR}.sha256"
  FINAL_MEDIA="$(maybe_encrypt "${MEDIA_TAR}")"
  info "media artifact ${FINAL_MEDIA}"
fi

info "backup complete under ${STAMP_DIR}"
info "copy to off-host destination from secret store; until proven: EXTERNAL_EVIDENCE_REQUIRED"
echo "EXTERNAL_EVIDENCE_REQUIRED"
