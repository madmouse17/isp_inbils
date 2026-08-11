#!/usr/bin/env bash
# Validate ops scripts/docs exist; bash -n; --help exits 0; refuse-pattern assertions.
# Does not touch real databases by default.
# shellcheck shell=bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

usage() {
  cat <<'EOF'
Usage: self-check.sh [--help]

Checks:
  - required docs and scripts exist
  - bash -n syntax on *.sh
  - each script --help exits 0
  - refuse patterns present (blank target, protected DB names)
  - BACKUP_RESTORE.md mentions EXTERNAL_EVIDENCE_REQUIRED, RPO, RTO
  - restore dry-run refuses blank database
Never runs mysqldump against a live DB by default.
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

FAILS=0
pass() { echo "PASS: $*"; }
fail() { echo "FAIL: $*" >&2; FAILS=$((FAILS + 1)); }

REQUIRED_DOCS=(
  "${REPO_ROOT}/docs/operations/DEPLOYMENT.md"
  "${REPO_ROOT}/docs/operations/INCIDENT_ROLLBACK.md"
  "${REPO_ROOT}/docs/operations/BACKUP_RESTORE.md"
  "${REPO_ROOT}/docs/operations/RESTORE_DRILL_TEMPLATE.md"
  "${REPO_ROOT}/docs/operations/SUPERVISION.md"
)

REQUIRED_SCRIPTS=(
  "${SCRIPT_DIR}/common.sh"
  "${SCRIPT_DIR}/backup.sh"
  "${SCRIPT_DIR}/restore.sh"
  "${SCRIPT_DIR}/restore-drill.sh"
  "${SCRIPT_DIR}/health-check.sh"
  "${SCRIPT_DIR}/deploy-local.sh"
  "${SCRIPT_DIR}/self-check.sh"
)

for f in "${REQUIRED_DOCS[@]}" "${REQUIRED_SCRIPTS[@]}"; do
  if [[ -f "$f" ]]; then
    pass "exists $(basename "$f")"
  else
    fail "missing $f"
  fi
done

for f in "${SCRIPT_DIR}"/*.sh; do
  if bash -n "$f"; then
    pass "bash -n $(basename "$f")"
  else
    fail "bash -n $(basename "$f")"
  fi
done

for name in backup restore restore-drill health-check deploy-local self-check; do
  if bash "${SCRIPT_DIR}/${name}.sh" --help >/dev/null; then
    pass "${name}.sh --help"
  else
    fail "${name}.sh --help"
  fi
done

# Refuse-pattern static assertions
if grep -q 'refuse blank' "${SCRIPT_DIR}/common.sh" \
  && grep -q 'PROTECTED_DB_NAMES_REGEX' "${SCRIPT_DIR}/common.sh"; then
  pass "common.sh refuse/protected patterns"
else
  fail "common.sh missing refuse/protected patterns"
fi

if grep -q 'i-understand-production-restore' "${SCRIPT_DIR}/restore.sh" \
  && grep -q 'allow-protected' "${SCRIPT_DIR}/restore.sh"; then
  pass "restore.sh dual production flags"
else
  fail "restore.sh missing dual production flags"
fi

if grep -q 'EXTERNAL_EVIDENCE_REQUIRED' "${REPO_ROOT}/docs/operations/BACKUP_RESTORE.md" \
  && grep -qi 'RPO' "${REPO_ROOT}/docs/operations/BACKUP_RESTORE.md" \
  && grep -qi 'RTO' "${REPO_ROOT}/docs/operations/BACKUP_RESTORE.md"; then
  pass "BACKUP_RESTORE.md EXTERNAL_EVIDENCE_REQUIRED RPO RTO"
else
  fail "BACKUP_RESTORE.md missing EXTERNAL_EVIDENCE_REQUIRED/RPO/RTO"
fi

# Runtime refuse blank target (no DB touch)
set +e
OUT="$(bash "${SCRIPT_DIR}/restore.sh" --input /tmp/x.sql --database '' 2>&1)"
RC=$?
set -e
if [[ $RC -ne 0 ]] && echo "${OUT}" | grep -qi 'refuse blank'; then
  pass "restore refuses blank database"
else
  fail "restore did not refuse blank database (rc=${RC})"
fi

set +e
OUT="$(bash "${SCRIPT_DIR}/restore.sh" --input /tmp/x.sql --database inbils 2>&1)"
RC=$?
set -e
if [[ $RC -ne 0 ]] && echo "${OUT}" | grep -qi 'protected'; then
  pass "restore refuses protected name inbils"
else
  fail "restore did not refuse protected name (rc=${RC})"
fi

set +e
OUT="$(bash "${SCRIPT_DIR}/backup.sh" --database '' --output-dir /tmp 2>&1)"
RC=$?
set -e
if [[ $RC -ne 0 ]] && echo "${OUT}" | grep -qi 'refuse blank'; then
  pass "backup refuses blank database"
else
  fail "backup did not refuse blank database (rc=${RC})"
fi

if [[ "${FAILS}" -eq 0 ]]; then
  echo "self-check: ALL PASS"
  exit 0
fi
echo "self-check: ${FAILS} FAIL(S)" >&2
exit 1
