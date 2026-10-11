# SECURITY REVIEW: mobile API v1 (auth, tokens, uploads, payments, tenancy)

Status: done
Role: security-reviewer
Blocked by: 08
Card: none (new lane; no Hermes card)
Skills: web-security-review, code-review, laravel-best-practices, caveman

## Scope

This is the mandatory review (generic rule 10: the lane touches auth, permissions, payments, uploads and user data). The reviewer reads the diff of tickets 01-07 and the matrix results of 08.

- Authentication:
  - Credential and 2FA paths share the web budgets.
  - There is no token before the second factor.
  - Challenge ids are unguessable, single-use and bounded.
  - Responses do not reveal which accounts exist.
  - The legacy-password gate holds.
- Tokens:
  - Tokens are stored hashed (Sanctum default) and expire.
  - Refresh rotates the token.
  - Every Q6 revocation trigger works.
  - No token text appears in logs, audit rows, exceptions or test output.
- Authorization:
  - The abilities fence holds, and the app-access guard runs on every request.
  - Policies are reused, with no API-only shortcuts.
  - Another customer's id or another company's id is 404.
  - The Q12 evidence fix holds on the web and in the API.
- Uploads: MIME and extension both checked, 10 MB size limit, private disk, inline only for raster images, no path or filename trust, dedupe by key.
- Payments: no Snap token in any response, `can_pay` is consistent with the service, the finish page carries no data, and the throttle is in place.
- Idempotency: the response cache is keyed per user, so one user cannot replay another user's response. The fingerprint covers the body and file. No 5xx is cached.
- Data exposure: the allow-list resources hold no internal or secret fields (the ticket 08 key walk).
- Dependencies: `composer audit` and `npm audit` are pasted, with every advisory reported in the rule-11 format.

## Owned files

- none (review only; findings go to the owning ticket as `needs-change`)

## Acceptance criteria

- [ ] The verdict is APPROVED, or BLOCKED with numbered findings (severity, confidence, file:line, a reproducing request, and a fix owner). Blocked findings are fixed red-first in the owning ticket and re-reviewed.
- [ ] The Q12 evidence gap is confirmed or refuted in writing, with the test that proves it.
- [ ] The `composer audit` and `npm audit` outputs are pasted.
- [ ] The two Flutter release tickets cite this verdict.

## Comments

### Review 2026-10-11 (security-reviewer): APPROVED after fixes

- MOBILE-01 (high, unassigned technician read SPK detail): detail now 404 unless assignee (`WorkOrderController.php`); tests in `WorkOrdersTest`.
- MOBILE-02 (medium, shared media bypassed persona fence): `Api/V1/MediaController.php` scopes by token app; dual-role tests in `Shared/MediaTest`.
- MOBILE-03 (low, evidence extension allow-list): `extensions:` added; renamed `.exe` rejected (`EvidenceTest`).
- Gaps noted, not blocking: no non-assignee submit test, no technician ticket-media allow/deny test.
- `composer audit`: no advisories; `npm audit`: 0 vulnerabilities (2026-10-11).
