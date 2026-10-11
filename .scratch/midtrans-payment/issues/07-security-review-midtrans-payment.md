# SECURITY REVIEW: Midtrans payment, webhook and portal release gate

Status: needs-owner-approval
Role: security-reviewer
Blocked by: 06
Card: none (new lane; no Hermes card)

## Scope

Read-only `security-reviewer` pass (repo rule 10: payments, auth, user data). Review tickets 01-06 against `../spec.md`:

- Webhook: signature verification (constant time, over the raw fields), replay/idempotency, amount binding, tenant binding, response behaviour (no oracle), CSRF exception scope, throttling.
- Secrets: server key only from env, never logged/serialised/in props/exports/test output; `.env` not touched.
- Customer portal: IDOR on invoices/attempts, role separation from admin routes, password-change gate, rate limit on Pay, open-redirect on the return page, `redirect_url` handling.
- Financial integrity: payment ledger immutability preserved, `received_by` null handling, exception paths leave no half-written state, DB uniqueness backstops.
- The customer-role ticket-visibility finding from ticket 03 and its disposition.
- Dependency advisories: `composer audit`, `npm audit`, every severity (package, CVE, severity, affected vs fixed, update command); no new Composer package expected (Q4b).

## Acceptance criteria

- [ ] Verdict APPROVED or BLOCKED with file:line findings and a fix per finding.
- [ ] Advisory table present (or "none" with the command output).
- [ ] Explicit statement that production use remains blocked pending Q1 follow-up (real merchant account, public HTTPS URL, production keys set only on the host).
