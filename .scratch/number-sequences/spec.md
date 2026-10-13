# Automatic codes with configurable prefix and format (Penomoran)

Status: done
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `number-sequences`
Source: owner request 2026-10-13: audit codes that are typed by hand, make them automatic, and add one page for custom prefixes and code formats. Each entity keeps its own format. The owner approved grilling round 1 (Q1–Q8, all recommended) and asked for implementation ("ya implementasikan dengan specs atau tickets saya sudah setuju").

## Audit (2026-10-13)

| Source | Entities |
|---|---|
| `NumberSequenceService` | invoice `INV`, spk `SPK`, subscription `SUB`, network_asset `NA` |
| Own generator | ticket `TKT-YYYY-NNNNN` (`TicketService::generateCode`, yearly reset by prefix); inventory_request `REQ-YYYYMMDD-NNNN` (`InventoryRequestService::generateCode`, no lock, 4 digits) |
| Typed by hand, now automatic | `customers.code`, `employee_profiles.employee_number`, `products.sku` |
| Typed by hand, stays manual | location, organization unit, category, document type, unit symbol, service package, company (RADIUS realm), VLAN, IP prefix, ODN port, plate number, serial number, payment reference |

Engine gaps: the format is fixed (`PREFIX[-YYYY]-00001`), the counter never resets, the Penomoran page is read-only, and rows appear only on first use.

## Decisions

The owner approved Q1–Q8. R1–R6 were decided by the assistant on the owner's behalf ("anda sebagai saya") and can be reversed.

- **Q1 Scope.** Nine entities sit on one engine: invoice, spk, subscription, network_asset, ticket, inventory_request, customer, employee, product. Every other code stays manual.
- **Q2 Manual input.** Each entity has an `allow_manual` switch. Defaults:

  | allow_manual | Entities |
  |---|---|
  | `true` | product (SKU) |
  | `false` | all the others |

  - When manual input is allowed, the form field is optional; leaving it empty generates a code.
  - When manual input is not allowed, a create request that carries the field gets a 422. On update the field is ignored, so the stored code never changes.
- **Q3 Template tokens.**
  - Tokens: `{PREFIX}`, `{YYYY}`, `{YY}`, `{MM}`, `{DD}`, `{COMPANY}` (companies.code), `{SEQ:n}` (n = 1–10 digits, zero-padded, exactly one per template).
  - Literal characters allowed: `A–Z a–z 0–9 - _ / .`
  - Existing rows are converted to the template they produce today: `{PREFIX}-{YYYY}-{SEQ:p}`, or `{PREFIX}-{SEQ:p}` when `year_suffix` is false. Then `padding` and `year_suffix` are dropped.
- **Q4 Reset period.**
  - Options: never, yearly, monthly, daily.
  - Required tokens: yearly needs `{YYYY}` or `{YY}`; monthly needs a year token and `{MM}`; daily needs a year token, `{MM}` and `{DD}`.
  - Defaults: ticket yearly, inventory_request daily, all others never.
- **Q5 One page.** Administrasi → Penomoran becomes the editor for the fixed list of nine registered entities. There is no "new type" button.
  - Every row is shown with its defaults, whether or not it has been used yet.
  - Each row can edit prefix, template, next number, reset period and allow_manual.
  - A live preview shows the next code.
- **Q6 Existing data.**
  - Generation skips any code that already exists in the entity's column (soft-deleted rows included), under the same row lock.
  - A row created for an entity starts at 1 + the highest sequence number among existing codes that match its template in the current period.
  - Free-form legacy codes that do not match the template are left alone. They are only skipped if they collide.
- **Q7 Format changes.** Existing codes never change. The page warns "berlaku untuk data baru saja". Every update is written to the audit log.
- **Q8 Scope.** Settings are per company (each subsidiary has its own). Permission: `system.setting`.
- **R1 Form UI.**
  - When manual input is not allowed, the code field is read-only. Create forms show "Dibuat otomatis saat disimpan, perkiraan: X".
  - When manual input is allowed, the field is optional and its placeholder shows the preview.
  - The preview is a guess: a parallel create may take that number.
- **R2 Default formats.**

  | Entity | Prefix | Template |
  |---|---|---|
  | invoice | `INV` | `{PREFIX}-{YYYY}-{SEQ:5}` |
  | spk | `SPK` | `{PREFIX}-{YYYY}-{SEQ:5}` |
  | subscription | `SUB` | `{PREFIX}-{YYYY}-{SEQ:5}` |
  | network_asset | `NA` | `{PREFIX}-{YYYY}-{SEQ:5}` |
  | ticket | `TKT` | `{PREFIX}-{YYYY}-{SEQ:5}` |
  | inventory_request | `REQ` | `{PREFIX}-{YYYY}{MM}{DD}-{SEQ:4}` |
  | customer | `CUS` | `{PREFIX}-{SEQ:5}` |
  | employee | `EMP` | `{PREFIX}-{SEQ:4}` |
  | product | `PRD` | `{PREFIX}-{SEQ:5}` |

  Rows that already exist keep their stored prefix.
- **R3 Module boundary.**
  - Core never imports module models. Each module registers its entity in its own ServiceProvider with label, table, column, max length and defaults.
  - The "already taken" check runs through the query builder on the registered table and column, scoped by `company_id`.
- **R4 Time.** Period keys and date tokens use the company timezone (`$company->timezone ?: config('app.timezone')`), server and client preview alike. A daily `REQ` counter therefore rolls over at local midnight, not UTC.
- **R5 Gaps.** `generate()` runs inside the caller's transaction where one exists, so a rolled-back create does not burn a number.
- **R6 Bypass.** Seeders and demo/E2E fixtures may keep writing explicit codes directly. Application create paths (web, API, actions) go through the service.
- **R7 Dead page.** `resources/js/Pages/Admin/Products/Create.tsx` was rendered by no route; deleted in this lane.

## Contract

See `issues/01-backend-sequence-engine.md` (owner of the API) for the PHP signatures and the page props consumed by tickets 02–03.

## Testing decisions

- Unit/feature tests on the engine:
  - template validation;
  - every token;
  - reset per period, including the boundary;
  - skip-taken;
  - ensure-from-max;
  - per-company isolation;
  - concurrency via the row lock (existing pattern);
  - allow_manual enforcement on create and update for customer, employee and product.
- Existing tests that assert old code formats are updated, not deleted.
- E2E covers:
  - editing a sequence on the Penomoran page and seeing the preview;
  - creating a customer without typing a code and getting the configured format;
  - a product with a typed SKU and one without.

## Tickets

01-backend-sequence-engine · 02-backend-entity-adoption · 03-ui-penomoran-editor-and-forms · 04-qa-review-release
