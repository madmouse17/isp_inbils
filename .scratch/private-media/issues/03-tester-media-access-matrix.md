# TESTER: media access matrix

Status: done
Role: qa
Blocked by: 01-backend-private-media, 02-ui-media-links
Card: none (new lane; no Hermes card)

## Scope

Matrix of actor (guest, customer own, customer other, staff same company, staff other company, admin) × owner type (ticket attachment, SPK evidence, customer document, employee document, vehicle document) × action (preview, download), plus the old `/storage/` path after migration.

## Acceptance criteria

- [x] PHPUnit matrix green on `inbils_test`; every denied cell asserts the exact status (302 login, 403, 404). Employee and vehicle documents have no writer (`DocumentService` accepts only `Customer` owners), so those cells do not exist yet.
- [x] E2E: ticket attachment upload + preview as staff and as the owning customer; the other customer is denied (`tests/e2e/private-media.spec.ts`; `E2eFixtureSeeder` now links the demo customer user to `E2E Linked Customer`).
- [ ] Evidence (commands + counts) recorded in the ledger closure: the integration owner pastes it from the lane report.
