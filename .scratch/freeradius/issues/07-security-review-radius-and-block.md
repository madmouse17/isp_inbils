# SECURITY REVIEW: RADIUS credentials, Disconnect channel, tenancy, block/unblock

Status: ready-for-agent
Role: security-reviewer
Blocked by: 06
Card: none (new lane; no Hermes card)

## Scope

Read-only `security-reviewer` pass (repo rule 10: credentials, subscriber data, suspension of paying customers) over tickets 01-06 against `../spec.md`:

- Secrets at rest and in transit: `encrypted` + `$hidden` on `nas_devices.radius_secret` and the subscriber secret; no `LogsActivity` on `NasDevice`/`NetworkAccount`; cleartext only in the `radius` DB (`Cleartext-Password`, `nas.secret`); audit, exceptions, failed-job reports (`app/Listeners/ReportFailedQueueJob.php:13`), logs and Inertia props free of secrets; `dontFlash` for `radius_secret`; one-time `network_account.secret` flash; APP_KEY rotation note.
- RADIUS DB: separate connection and least-privilege grants (Q1); no raw SQL with user input in `RadiusDirectory`; switch off means zero queries; Inbils never deletes `nas` or user rows it did not write.
- Disconnect sender: RFC 5176 authenticator, response authenticator verified (forged ACK rejected), identifier check, short timeout, no packet logging; CoA target only the company's own NAS `management_ip`:`coa_port` (no staff-supplied free host per request; probing other services through error messages ruled out).
- Tenancy: realm suffix and global uniqueness without cross-company disclosure; NAS `nasname` uniqueness; jobs in `CompanyService::runAs`; a Disconnect for company A never goes to company B's NAS; FreeRADIUS realm policy (Q4) present in the runbook and proven in ticket 06.
- Block/unblock: `customer.subscription.block` on both routes, tenant-scoped binding, 404 for other companies, reason length, audit with causer; payment (manual and Midtrans) can never lift a block; auto-suspend and Q16 unchanged.
- Removal: no leftover RouterOS code path, env key or credential column.
- Dependency advisories: `composer audit` and `npm audit`, every severity, as a table (package, CVE, severity, affected vs fixed version, exact update command). `composer.json` changed (RouterOS library and `spatie/ssh` removed; no new package under Q8 b).

Owned files: none (findings go to the owning ticket as `needs-change`).

## Acceptance criteria

- [ ] Verdict APPROVED or BLOCKED with `file:line` findings, severity and fix per finding; re-review after fixes.
- [ ] Advisory table present (or "none" with the pasted command output).
- [ ] Statement that production `RADIUS_ENABLED` stays off until ticket 06's lab evidence, then routers move to RADIUS one at a time.

## Comments
