# TESTER: mobile API security and contract matrix, live smoke over the compose stack

Status: ready-for-agent
Role: tester (qa)
Blocked by: 07
Card: none (new lane; no Hermes card)
Skills: tdd, web-security-review, playwright-best-practices (not used for UI here), ponytail, caveman

## Scope

An independent check of the whole lane against the spec's Testing Decisions matrix. Fill gaps with tests; never change production code. A defect becomes a `needs-change` to the owning ticket.

- Matrix (one test file per row group under `tests/Feature/Api/V1/Matrix/`):
  - Abilities: a customer token on every technician route is 403, and the reverse. This is generated from `route:list`, so a new route cannot escape it.
  - Tenancy: for each read route, another customer's id is 404 and another company's id is 404.
  - Token lifecycle: expired, refreshed-away, logged-out and device-revoked tokens are 401. Each Q6 trigger revokes.
  - 2FA and legacy password paths.
  - Idempotency on every mutating route, generated from `route:list`: a replay is identical, a mismatch is 422, a missing key is 428.
  - The secret-key walk on every GET response from a seeded dataset: no `password`, `snap_token`, `two_factor_*`, network-account fields, `tax_id`, internal `notes`, `received_by`, or internal comments for customers.
  - JSON-only errors for unknown routes, 405, and 500 with debug off.
- Live smoke on the compose stack, `http://localhost:8080`. Run `tests/e2e/setup.php` first, because PHPUnit on `inbils_test` wipes the demo users. Then `curl`: customer login → profile → invoices → pay (sandbox gateway on a dev host, or the `payment_unavailable` path when keys are absent) → create ticket → comment; technician login → my-work → SPK detail → start → upload one JPEG → declare → submit. Record status codes and redacted bodies (no tokens) in a ticket comment.
- Confirm the documented emulator path: `adb reverse tcp:8080 tcp:8080`, then a request from the AVD to `http://localhost:8080/api/v1/auth/login` reaches Laravel. Confirm that a request with host `10.0.2.2` is rejected by `trustHosts` while `APP_ENV=production`.

## Owned files

- NEW `tests/Feature/Api/V1/Matrix/`

## Acceptance criteria

- [ ] Every row of the spec's Testing Decisions matrix maps to at least one passing test, listed in a ticket comment. The generated ability and idempotency checks cover 100% of `api/v1` routes.
- [ ] The full isolated PHPUnit run is green on `inbils_test` (serialized). The command and its summary line are pasted.
- [ ] The live smoke transcript is pasted with tokens redacted, and every step returns the documented status.
- [ ] The emulator reachability result is recorded: `adb reverse` works, and `10.0.2.2` gives 400 under production env (or the actual behaviour, if different, as a finding).
- [ ] Any defect is a `needs-change` to the owning ticket with a reproducing test. There are no production-code edits in this ticket.

## Comments
