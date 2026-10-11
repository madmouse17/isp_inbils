# REVIEWER: sandbox end-to-end evidence and release readiness

Status: ready-for-agent
Role: reviewer
Blocked by: 07
Card: none (new lane; no Hermes card)

## Scope

- Run one real Midtrans **sandbox** payment end to end over a public HTTPS tunnel (Midtrans cannot call localhost): create attempt in the portal, pay with a sandbox method, receive the real notification, confirm one payment and invoice status; then run `midtrans:reconcile` and confirm no change. Evidence = log/screenshot excerpts with all keys redacted.
- Confirm docs updated: `docs/ENTERPRISE_ISP_READINESS.md` (scope decision), `docs/business/billing.md` (methods, attempt state machine, portal), CHANGELOG; ledger entry added and `.scratch` status reconciled.
- Confirm deploy notes: `php artisan migrate`, `php artisan db:seed --class=RolePermissionSeeder --force`, four env values set on the host, Midtrans dashboard Payment Notification URL, scheduler running.
- Rollback note: migrations down (`payment_attempts`, `payments.method`, `received_by` NOT NULL only if no null rows exist — document the precondition).

## Acceptance criteria

- [ ] Verdict APPROVED or BLOCKED with file:line findings.
- [ ] Sandbox evidence attached without any key material.
- [ ] Release notes and rollback precondition recorded in the ledger.
