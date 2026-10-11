# SECURITY REVIEW: router credentials, subscriber secrets, provisioning and isolir release gate

Status: done
Role: security-reviewer
Blocked by: 06
Card: none (new lane; no Hermes card)

## Scope

Read-only `security-reviewer` pass (repo rule 10: credentials, subscriber data, automatic suspension of paying customers). Review tickets 01-06 against `../spec.md`:

- Secrets at rest and in transit: `encrypted` casts and `$hidden` on both password columns; no `LogsActivity` on the two models; audit properties, exception messages, failed-job reports (`app/Listeners/ReportFailedQueueJob.php:13`) and logs free of passwords, Basic auth headers and router response bodies; jobs carry ids only; the one-time `Inertia::flash` of the subscriber password while sessions are database-backed and unencrypted (`.env.example:30,32`), compared with the accepted two-factor recovery-code precedent (`app/Http/Controllers/Auth/TwoFactorAuthenticationController.php:41,50`); APP_KEY rotation note.
- Router channel: HTTPS only, certificate verification never disabled (no flag, no environment escape), `ROUTEROS_CA_BUNDLE` handling, redirects not followed, the staff-entered `management_ip`/port cannot be used to probe other internal services through error messages (only the RouterOS `message` field is surfaced, capped), RouterOS user-group policies minimal and without `sensitive` (list from ticket 02).
- Authorisation and tenancy: every route gated by its permission; tenant-scoped binding and `ensureSameCompany` on NAS router, subscription and network account ids (IDOR); a network account can never reference another company's router; jobs and the observer resolve rows in the right company under `runAs`; unreachable notifications reach only users who can act in that company; role mapping (router credentials admin only).
- Reveal: rate limit, audit with causer, no value in the audit; generated passwords' length and alphabet.
- Business-logic abuse: automatic isolir cannot hit a paid or cancelled invoice, a pending or terminated subscription, or another company; automatic reconnection only after a real recorded payment (Midtrans signature path unchanged) and never for `manual` suspensions; the client-supplied `source` on suspend is limited to `manual|overdue` behind `customer.subscription.suspend`.
- Public isolir page: only public company fields, unknown code 404, throttled, output escaped.
- Dependency advisories: `composer audit` and `npm audit`, every severity, as a table (package, CVE, severity, affected vs fixed version, exact update command). No new Composer package is expected under Q3a; under Q3b review `evilfreelancer/routeros-api-php` and `spatie/ssh` as well.

Owned files: none (findings go to the owning ticket as `needs-change`).

## Acceptance criteria

- [ ] Verdict APPROVED or BLOCKED with `file:line` findings, severity and a fix per finding; re-review after fixes.
- [ ] Advisory table present (or "none" with the pasted command output).
- [ ] Explicit statement that production writes stay off until ticket 08's lab evidence and then start router by router with the write flag.

## Comments

### Re-review 2026-10-11 (security-reviewer): APPROVED

- Earlier BLOCK findings fixed: pending-disconnect intent persisted independent of profile diff; adoption approval bound to exact target/password revision (`ApplyNetworkAccountJob`); sealed password survives Inertia version redirect.
- MIKROTIK-07-004 (high, retarget while an unrecorded disconnect is owed) fixed: `NetworkAccountService.php:77-80` rejects router/service-type/username change until the drop settles; test `SecurityHardeningTest.php` (first-adoption failure, rejection, retry, then rename allowed). Withdrawn after re-review.
- Q16 (reconnect after token payment) stays OPEN in `../spec.md` for the owner; not implemented.
- `composer audit`: no advisories. `npm audit`: 0 vulnerabilities (2026-10-11).
- Production writes stay off until ticket 08 lab evidence; then enable router by router with the write flag.
