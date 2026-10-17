# QA: fresh deploy, fixtures and personas for the audit

Status: ready-for-agent
Role: qa
Blocked by: every other lane merged
Spec: `../spec.md`

## Scope

On the dev stack (Sail, DB `inbils`, never `inbils_test`; no resets): create the audit tenant via the platform panel (not the seeder) so flow 1 is real, name it `AUDIT ISP <date>`; the existing demo tenant stays for volume flows; AVD with both apps pointing at the stack; personas: platform admin, ISP admin, manager, staff, warehouse, technician, customer; a scratch `docs/plans/flow-audit/` folder.

## Acceptance criteria

- [ ] Stack + apps reachable; personas can log in; folder exists.

## Comments
