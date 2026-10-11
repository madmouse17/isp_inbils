# BACKEND: ticket endpoints for customers (create, list, show, comment) and technicians (show, start, resolve, comment)

Status: done
Role: backend
Blocked by: 01
Card: none (new lane; no Hermes card)
Skills: laravel-best-practices, tdd, web-security-review, ponytail, caveman

## Scope

Implements the ticket rows of the spec contract (customer and technician) and Q15a (no customer uploads).

- `Modules/Ticketing/routes/api.php` (NEW), mapped by the Ticketing `RouteServiceProvider` under `api/v1`.
- Customer (`api.customer`):
  - `GET customer/ticket-categories`: active categories of the company, the same query as the web create form (`Modules/Ticketing/app/Http/Controllers/TicketController.php:172`).
  - `GET customer/tickets` with `status=active|closed|all`, where active = `open|assigned|on_progress` and closed = `resolved|closed`. Scoped to the resolved customer as on the web (`:148-149`), newest first.
  - `POST customer/tickets`: a FormRequest that extends `StoreTicketRequest` and only merges `source=customer` before the parent preparation. All rules, ownership checks (`StoreTicketRequest.php:96-150`) and `ticketAttributes()` stay the parent's. The controller calls `TicketService::create($request->ticketAttributes(), $user->id)`. 201 returns the customer ticket.
  - `GET customer/tickets/{id}`: looked up inside the customer's own tickets (another customer's id is 404, never 403), then `TicketPolicy::view`. Comments exclude `is_internal`, as on the web (`TicketController.php:208-212`).
  - `POST customer/tickets/{id}/comments`: `StoreTicketCommentRequest`, which already forces `is_internal=false` for customers (`:16-18`), plus `TicketPolicy::comment` and `TicketService::addComment`.
- Technician (`api.technician`):
  - `GET technician/tickets/{id}`: `TicketPolicy::view`, with internal comments included, customer `{name, phone}`, subscription `{id, code}` and location `{name, address, lat, lng}`.
  - `POST …/start`: `TicketPolicy::start`, then `TicketService::startWork`.
  - `POST …/resolve`: `ResolveTicketRequest` unchanged (`Modules/Ticketing/app/Http/Requests/ResolveTicketRequest.php:9-19`: `ticket.resolve`, `resolution_note` required, string, ≤1000), `TicketPolicy::resolve`, then `TicketService::resolve`.
  - `POST …/comments`: `StoreTicketCommentRequest` (with `is_internal` allowed), `TicketPolicy::comment`, then `TicketService::addComment`.
  - Conflicts from the services' expected-status checks surface as 409 `conflict` or 422 `unprocessable`; the app re-reads the ticket.
- New allow-list resources:
  - Customer ticket: list fields; detail adds `description`, `resolution_note`, `comments[{id, body, author:{name}, mine, created_at}]`, `attachments[{id, file_name, mime_type, size}]`.
  - Technician ticket: the same, plus `is_internal` on comments and the customer, subscription and location blocks.
  - Never serialize `TicketResource`.

Non-goals: customer attachment upload (Q15a), assignment, close, spawn SPK, ticket list for technicians (they use my-work, ticket 02), changes to `TicketService`, `TicketPolicy` or the existing FormRequests.

## Owned files

- NEW `Modules/Ticketing/routes/api.php`; `Modules/Ticketing/app/Providers/RouteServiceProvider.php`
- NEW `Modules/Ticketing/app/Http/Controllers/Api/V1/Customer/`, NEW `Modules/Ticketing/app/Http/Controllers/Api/V1/Technician/`
- NEW `Modules/Ticketing/app/Http/Requests/Api/V1/` (customer store request), NEW `Modules/Ticketing/app/Http/Resources/Api/V1/` (customer and technician ticket and comment resources)
- NEW `tests/Feature/Api/V1/Customer/TicketsTest.php`, NEW `tests/Feature/Api/V1/Technician/TicketsTest.php`

## Acceptance criteria

- [x] A customer creates a ticket with only `category_id` and `title`. `source` is `customer`, `customer_id` is their own, and `priority` falls back to the category default. Another customer's `subscription_id`, or a network asset that is not theirs, gives 422 with the existing messages.
- [x] The customer list and detail never include another customer's tickets (404) or internal comments, as verified by seeding one internal comment. A recursive key check finds no `assigned_to`, `created_by`, `network_asset_id`, `location_id` or `spawned_spk_id`.
- [x] A customer comment is stored with `is_internal=false` even if the body says `true`. A comment on a ticket the customer does not own is 404.
- [x] A technician starts an `assigned` ticket assigned to them (200, status `on_progress`) and resolves it with a note (200, `resolved`). A note over 1000 characters is 422. Another technician gets 403. Starting twice gives 409 or 422, and the state is unchanged.
- [x] A technician can post an internal comment, and it never appears in the customer view of the same ticket.
- [x] Every mutation replays identically with the same `Idempotency-Key`. The ticket and comment counts do not grow on replay.
- [x] The existing ticketing tests stay green (`tests/Feature/Ticketing/*`).
- [x] Pint is clean and Larastan is clean on changed files.

## Comments

### 2026-10-11: done (backend agent MobileApiTicketing)

Implemented customer and technician ticket API routes under `api/v1` with explicit allow-list resources. Customer routes scope every lookup through the resolved customer request attribute. Technician routes reuse `TicketService`, existing requests, and `TicketPolicy`/service checks. GPS check-in for ticket start is stored in `activity_log` properties through `App\Support\FieldCheckIn`, with distance to ticket location or subscription installation address when coordinates exist, plus the ticket Show page summary.

Red first: `tests/Unit/Support/FieldCheckInTest.php` failed on missing `App\Support\FieldCheckIn`; initial focused route bootstrap also failed until controllers landed. Final focused run: 42 passed, 150 assertions. Existing Ticketing suite: 78 passed, 380 assertions.
