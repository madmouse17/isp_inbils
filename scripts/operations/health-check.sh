#!/usr/bin/env bash
# Curl local /up and /ready. No secrets in output.
# shellcheck shell=bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

usage() {
  cat <<'EOF'
Usage: health-check.sh [--base-url URL]

Checks:
  GET {base}/up     process liveness
  GET {base}/ready  dependency readiness JSON {status}

Default base-url: http://127.0.0.1:8000
Exit 0 only if both pass (/ready status ready).
EOF
}

BASE_URL="http://127.0.0.1:8000"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --base-url) BASE_URL="${2:-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown arg: $1" ;;
  esac
done

refuse_blank_target "base-url" "${BASE_URL}"
require_cmd curl

UP_CODE="$(curl -sS -o /tmp/inbils_up_body.$$ -w '%{http_code}' "${BASE_URL%/}/up" || true)"
READY_BODY_FILE="/tmp/inbils_ready_body.$$"
READY_CODE="$(curl -sS -o "${READY_BODY_FILE}" -w '%{http_code}' "${BASE_URL%/}/ready" || true)"

# Redact accidental credential-looking substrings from printed body
sanitize() {
  sed -E \
    -e 's/(password|passwd|pwd|secret|token)([\"=: ]+)[^,&\"} ]+/\1\2*** /Ig' \
    -e 's/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/***@***/g' \
    "$1" 2>/dev/null || true
}

BODY=""
if [[ -f "${READY_BODY_FILE}" ]]; then
  BODY="$(sanitize "${READY_BODY_FILE}")"
fi
info "GET /up -> HTTP ${UP_CODE}"
info "GET /ready -> HTTP ${READY_CODE}"
info "ready body: ${BODY}"

rm -f /tmp/inbils_up_body.$$ "${READY_BODY_FILE}" 2>/dev/null || true

[[ "${UP_CODE}" == "200" ]] || die "/up expected 200 got ${UP_CODE}"
[[ "${READY_CODE}" == "200" ]] || die "/ready expected 200 got ${READY_CODE}"
echo "${BODY}" | grep -q '"status"[[:space:]]*:[[:space:]]*"ready"' \
  || die "/ready body missing status ready"

info "health-check OK"
