# QA: fresh deploy, fixtures and personas for the audit

Status: in-progress
Role: qa
Blocked by: every other lane merged
Spec: `../spec.md`

## Scope

On the dev stack (Sail, DB `inbils`, never `inbils_test`; no resets): create the audit tenant via the platform panel (not the seeder) so flow 1 is real, name it `AUDIT ISP <date>`; the existing demo tenant stays for volume flows; AVD with both apps pointing at the stack; personas: platform admin, ISP admin, manager, staff, warehouse, technician, customer; a scratch `docs/plans/flow-audit/` folder.

## Acceptance criteria

- [ ] Stack + apps reachable; personas can log in; folder exists.

## Comments

- 2026-10-17 FlowAudit1 (QA): stack and personas prepared; ticket stays in-progress because customer and warehouse logins and the app launches are not yet verified.
  - Done: Sail stack up (http://localhost, Mailpit :8025, DB `inbils`); regression gate run once on `inbils_test`: 265 passed, 4 skipped, 17.6 min (first attempt blocked by a dead-run lock `tests/e2e/.runtime/canonical-e2e.lock`; removed after proving all 369 recorded pids dead).
  - Tenant `AUDIT ISP 2026-10-17` (code `AUDIT-ISP`, company id 3, public id 01m54s9jfnt05chtnb5nfmqx1q) created through the platform panel; platform operator `audit-operator@inbils.test` (2FA enrolled); first admin `audit-admin@inbils.test` via the invite mail (Mailpit), 2FA on.
  - Personas created in the tenant: manager, staff (CS), warehouse (same `staff` role; no warehouse role exists), technician (+ employee profiles EMP-0001..0004, vehicle D 1234 AUD). Web logins verified for platform admin, admin, manager, staff, technician (/app-only). Not yet verified: customer login (access link mail in Mailpit, 60 min from 18:39 WIB), warehouse login.
  - Secrets live outside the repo: `~/.config/inbils/flow-audit/{operator,personas,admin}.json`, `totp.mjs`.
  - Apps: customer and technician dev debug APKs built and installed on AVD `inbils_pixel7` (service `avd-pixel7`), `adb reverse tcp:8010 tcp:80`, local env `env/dev.local.json` deleted after the build (APKs keep the URL). Apps not launched yet.
  - Folder `docs/plans/flow-audit/` exists with screenshots 01-* .. 04-*.
  - Report: `docs/plans/FLOW_AUDIT_2026-10-17.md`.
