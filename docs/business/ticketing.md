# Module: Ticketing

> Status: DRAFT v2 (ISP pivot 2026-06-30). Phase 6 eksekusi. Dependency:
> Phase 1 (Core) + Phase 2 (Customer/Service) + Phase 3 (NetworkAsset) +
> Phase 4 (SPK). Lokasi module: `Modules/Ticketing/` (nwidart).
> ISP: Ticketing = workflow pelayanan ISP. Source: customer complaint /
> NOC monitoring / internal report. Kategori ISP (no internet, slow,
> packet loss, device issue, fiber issue).

## Tujuan

Mengelola tiket pelayanan ISP: dari customer complaint, NOC monitoring,
internal report. Auto-routing ke unit terkait, Kepala Unit assignment,
SLA tracking, spawn SPK untuk field work. Link ke customer + subscription
+ network asset + location untuk trace cepat. History: ticket + status +
assignment + response time + resolution time.

## User Role

| Role | Hak |
|------|-----|
| admin | full CRUD + assign + resolve + close + spawn SPK + delete |
| manager (Kepala Unit) | view + assign + resolve + close + spawn SPK (no delete) |
| noc | view + create (from monitoring) + assign to self + resolve + spawn SPK |
| staff | view + create (customer complaint intake) + comment (no assign/resolve) |
| technician | view assigned to self + comment + resolve (limited) |
| customer (v2 portal) | create own ticket + view own + comment (no assign) |

## Entity

### Ticket (Modules/Ticketing/Models/Ticket.php)
Tiket pelayanan. Source (customer/noc/internal). Kategori ISP. Link ke
customer + subscription + network_asset + location (issue location) +
spk (spawned). Status workflow. SLA tracking (deadline + breach).

### TicketCategory (Modules/Ticketing/Models/TicketCategory.php)
Kategori ISP: no_internet, slow_connection, packet_loss, device_issue,
fiber_issue. Default SLA per category (hours). Configurable + admin add
custom (v2).

### TicketComment (Modules/Ticketing/Models/TicketComment.php)
Komentar internal/public. Public = visible customer (v2 portal). Internal
= staff-only. Untuk collaboration + resolution notes.

### TicketAttachment (Modules/Ticketing/Models/TicketAttachment.php)
Lampiran (screenshot error, foto dari customer, log). Link ke comment
(optional) atau langsung ke ticket.

## Database Relation

```
companies ──< ticket_categories
companies ──< tickets
customers ──< tickets (FK customer_id nullable — wajib jika source=customer)
service_subscriptions ──< tickets (FK subscription_id nullable)
network_assets ──< tickets (FK network_asset_id nullable — traced asset)
locations ──< tickets (FK location_id nullable — issue location, POP/area)
users ──< tickets (FK created_by, assigned_to active)
ticket_categories ──< tickets (FK category_id)
work_orders ──< tickets (FK spawned_spk_id nullable — backlink SPK spawned)
tickets ──< ticket_comments (cascade)
users ──< ticket_comments (FK author_id)
tickets ──< ticket_attachments (cascade)
ticket_comments ──< ticket_attachments (FK comment_id nullable)
```

Skema: `docs/DATABASE.md` Section 10 (Ticketing).

## Entity Fields

### ticket_categories
- id, company_id FK NOT NULL, name NOT NULL, code NOT NULL, default_sla_hours
  int NOT NULL (jam kalender), default_priority enum(low/medium/high/urgent)
  NOT NULL default 'medium', is_active boolean default true, created_at,
  updated_at, deleted_at.
- Unique: `(company_id, code)`. Trait `BelongsToCompany`. Soft delete.
- Seed v1: no_internet (4h, urgent), slow_connection (8h, high),
  packet_loss (8h, high), device_issue (12h, medium), fiber_issue (12h,
  high).

### tickets
- id, company_id FK NOT NULL, code NOT NULL, title NOT NULL, description
  text nullable, source enum(customer/noc/internal) NOT NULL, category_id
  FK NOT NULL (restrict), status enum
  (open/assigned/on_progress/resolved/closed) NOT NULL default 'open',
  priority enum(low/medium/high/urgent) NOT NULL default 'medium',
  customer_id nullable FK→customers.id (restrict — wajib jika source=
  customer), subscription_id nullable FK→service_subscriptions.id
  (restrict), network_asset_id nullable FK→network_assets.id (restrict —
  traced asset), location_id nullable FK→locations.id (restrict — issue
  location), assigned_to nullable FK→users.id (restrict — active handler),
  spawned_spk_id nullable FK→work_orders.id (restrict — SPK spawned from
  ticket), sla_deadline timestamp nullable (set saat create = now +
  category.default_sla_hours), first_response_at timestamp nullable,
  resolved_at timestamp nullable, closed_at timestamp nullable,
  resolution_note text nullable, created_by FK→users.id NOT NULL,
  created_at, updated_at, deleted_at.
- Unique: `(company_id, code)`. Index: `(company_id, status)`,
  `(company_id, source)`, `(company_id, category_id)`,
  `(company_id, assigned_to)`, `(company_id, customer_id)`,
  `(company_id, sla_deadline)`, `(company_id, spawned_spk_id)`. Trait
  `BelongsToCompany`. Soft delete.
- Check: `source IN ('customer','noc','internal')`, `status IN ('open',
  'assigned','on_progress','resolved','closed')`, `priority IN ('low',
  'medium','high','urgent')`.
- SLA breach: computed (sla_deadline < now AND status NOT IN (resolved,
  closed)) — badge di list/dashboard, bukan kolom status.

### ticket_comments
- id, company_id FK NOT NULL, ticket_id FK NOT NULL (cascade), author_id
  FK→users.id NOT NULL (restrict), body text NOT NULL, is_internal
  boolean default false (true = staff-only, false = public visible
  customer v2), created_at, updated_at, deleted_at.
- Index: `(company_id, ticket_id)`, `(ticket_id, created_at)`. Trait
  `BelongsToCompany`. Soft delete.

### ticket_attachments
- id, company_id FK NOT NULL, ticket_id FK NOT NULL (cascade), comment_id
  nullable FK→ticket_comments.id (cascade), file_path varchar NOT NULL,
  original_name varchar, mime_type varchar, size_bytes int, uploaded_by
  FK→users.id NOT NULL, created_at, updated_at.
- Index: `(company_id, ticket_id)`, `(ticket_id, comment_id)`. Trait
  `BelongsToCompany`.
- File storage: local disk `tickets/{ticket_id}/` v1 (S3 v2).

## Workflow

Lihat `docs/WORKFLOW.md` Section 8 (Ticketing). Ringkas state machine:

```
[open] ──assign──→ [assigned] ──start work──→ [on_progress] ──resolve──→ [resolved] ──close──→ [closed]
                          │
                          └──assign+self-start──→ [on_progress] (shortcut)
[resolved] ──reopen (customer not satisfied)──→ [on_progress] (v2)
```

### Transisi & side effect

| Dari | Ke | Trigger | Side effect |
|------|-----|---------|-------------|
| open | assigned | assign(handler, Kepala Unit) | assigned_to set, status=assigned, AuditLog. |
| open/assigned | on_progress | startWork (handler, set first_response_at if null) | first_response_at=now if null (FRT tracking), status=on_progress. |
| on_progress | resolved | resolve(resolution_note) | resolved_at=now, resolution_note set, status=resolved. Hitung resolution_time = resolved_at - created_at. SLA compliance check. |
| resolved | closed | close | closed_at=now, status=closed. Terminal v1. |
| resolved | on_progress | reopen (v2) | status=on_progress, reopen reason. |

### SLA
- Saat create: `sla_deadline = now + category.default_sla_hours` (jam
  kalender, bukan jam kerja — v1 simple). Override per ticket (urgent
  priority = sla * 0.5).
- Saat resolve: `resolution_time = resolved_at - created_at`.
- Breach: `sla_deadline < now AND status NOT IN (resolved, closed)` →
  badge "SLA breached" + dashboard widget. Job harian `CheckSlaBreachJob`
  flag + notify (v2).
- First Response Time (FRT): `first_response_at - created_at` (saat
  handler pertama kali respond/start). Dipakai Reporting technician
  performance.

### Auto-routing (system suggest)
- Saat create: system tentukan unit terkait berdasarkan:
  - Category (no_internet/fiber_issue → NOC/field-fiber team;
    device_issue → field-device team; slow/packet_loss → NOC).
  - Location (area coverage → region team).
  - Customer/subscription (serving_pop → POP team).
  - Network asset (asset_type → team specialist).
- `SuggestHandlerQuery` return ranked kandidat (unit + skill + workload
  + availability). Kepala Unit final assign.
- v1: suggest via query, manual assign by Kepala Unit. v2: auto-assign
  berdasarkan rule engine.

### Spawn SPK from ticket
- Tiket category device_issue/fiber_issue yang butuh field work →
  `SpawnSpkFromTicketAction`:
  - Create SPK type=maintenance, source=ticket.
  - Pre-fill customer, subscription, network_asset, location dari ticket.
  - spawned_spk_id set (backlink).
  - SPK status=generated (menunggu Kepala Unit assign technician).
- Trigger: Kepala Unit manual (button "Spawn SPK") OR auto saat resolve-
  but-needs-field-work.

### Customer rating (v2 hook)
- Saat close: customer rating (1-5) optional. v1: field disimpan tapi
  customer portal v2. v1: staff input rating atas nama customer (optional).
- EmployeeEvaluation reference_type=Ticket bisa catat rating + score.

## Permission

```
ticket.view
ticket.create
ticket.update
ticket.delete
ticket.assign         (Kepala Unit)
ticket.start          (handler, self-assigned)
ticket.resolve        (handler)
ticket.close          (Kepala Unit)
ticket.reopen         (v2)
ticket.comment.create
ticket.comment.internal   (post internal comment)
ticket.attachment.upload
ticket.attachment.view
ticket.spawn_spk      (Kepala Unit)
ticket.export
ticket.manage         (super)
```

## API (Route)

Lihat `docs/API.md` Section 8 (Ticketing group). Ringkas:

- `GET    /admin/tickets` (list, filter: status, source, category, assigned_to, customer, sla_breached, date range)
- `POST   /admin/tickets` (create open)
- `GET    /admin/tickets/{ticket}` (detail + comments + attachments + linked customer/subscription/asset/location/spk)
- `PUT    /admin/tickets/{ticket}` (update open only)
- `DELETE /admin/tickets/{ticket}` (soft delete closed only)
- `POST   /admin/tickets/{ticket}/assign` (body: handler_id)
- `GET    /admin/tickets/{ticket}/suggest-handler` (system suggest)
- `POST   /admin/tickets/{ticket}/start` (on_progress, set first_response_at)
- `POST   /admin/tickets/{ticket}/resolve` (body: resolution_note)
- `POST   /admin/tickets/{ticket}/close`
- `POST   /admin/tickets/{ticket}/comments` (body: body, is_internal)
- `POST   /admin/tickets/{ticket}/attachments` (multipart upload)
- `GET    /admin/tickets/{ticket}/attachments`
- `DELETE /admin/tickets/{ticket}/attachments/{att}`
- `POST   /admin/tickets/{ticket}/spawn-spk` (create SPK maintenance from ticket)
- `GET    /admin/ticket-categories` (+ POST/PUT/DELETE)
- `GET    /admin/tickets/export`

## Testing Scenario

### Create + assign + resolve + close
1. Create ticket: source=customer, category=no_internet, customer C-001,
   subscription SUB-001, network_asset ONT-123 (traced), location POP-JKT01.
   sla_deadline = now + 4h. Status=open.
2. Kepala Unit suggest-handler → ranked (NOC/field team). Assign handler
   H01. Status=assigned.
3. H01 start: first_response_at=now (FRT tracking), status=on_progress.
4. H01 resolve (note "restart ONT, service back"): resolved_at=now,
   resolution_time computed. SLA compliance check (resolved < deadline =
   within SLA).
5. Kepala Unit close: closed_at=now. Terminal.

### Spawn SPK from ticket
1. Ticket fiber_issue, on_progress, needs field work (fiber cut).
2. Kepala Unit spawn-spk: SpawnSpkFromTicketAction → SPK type=maintenance,
   source=ticket, pre-fill customer/subscription/asset/location,
   spawned_spk_id set. SPK status=generated.
3. SPK assigned + completed → ticket resolved (or separate resolve).

### SLA breach
1. Ticket no_internet, sla_deadline=now-1h (past), status=on_progress.
2. `CheckSlaBreachJob` → flag breach. Badge "SLA breached" di list.
3. Dashboard widget: SLA breach count.

### Validation
1. Create ticket source=customer tanpa customer_id → 422.
2. Assign closed ticket → 422.
3. Resolve open ticket (not assigned/on_progress) → 422.
4. Close non-resolved ticket → 422.

### Authorization
1. Customer (v2) create own ticket → 200. View other → 403.
2. NOC create + assign to self + resolve → 200. Close → 403.
3. Technician view assigned-to-self → 200. Assign → 403.
4. Staff create + comment → 200. Resolve → 403.

### Number race
1. Concurrent create 2 ticket → 2 code beda (TKT-{YEAR}-{NNNNN}).

## Acceptance Criteria

- [ ] 3 source (customer/noc/internal) + 5 kategori ISP (no_internet/
  slow_connection/packet_loss/device_issue/fiber_issue) + custom (v2).
- [ ] Status state machine (open→assigned→on_progress→resolved→closed).
- [ ] SLA: deadline per category, breach detection (job + badge),
  resolution_time + FRT tracking.
- [ ] Auto-routing suggest (category + location + asset → ranked handler).
- [ ] Spawn SPK from ticket (SpawnSpkFromTicketAction, backlink).
- [ ] Link customer/subscription/asset/location (trace 1-klik).
- [ ] Comment internal/public + attachment.
- [ ] Code generation unique + race-safe (TKT-{YEAR}-{NNNNN}).
- [ ] Soft delete closed only.
- [ ] Policy per aksi (technician self-limit, customer v2 own-only).
- [ ] Activity log: create, assign, start, comment, resolve, close,
  spawn_spk, sla_breach.
- [ ] Factory + seeder (30 ticket: 10 open, 8 assigned, 7 on_progress,
  3 resolved, 2 closed — mix source/category, 3 SLA breached).
- [ ] Feature test ≥ 80% coverage.
- [ ] UI: ticket list (filter status/source/category/sla_breached/date),
  detail (comments thread + attachments + linked entities + SLA badge +
  lifecycle buttons + spawn-SPK button), NOC dashboard (active by
  category + breach count + my assigned), form, customer portal (v2).
- [ ] UI pakai Components/ui + composite (TicketSlaBadge).
- [ ] Dark mode + responsive.

## Module Dependencies

- **Depends on (Phase 1):** Core (Company, User, Role, Permission,
  ActivityLog, Setting), Location (shared Core — issue location).
- **Depends on (Phase 2):** Customer/Subscription (shared Core —
  customer_id + subscription_id direct FK, trace).
- **Depends on (Phase 3):** NetworkAsset (network_asset_id direct FK —
  trace aset terkait tiket).
- **Depends on (Phase 4):** SPK (spawned_spk_id backlink — SpawnSpkFromTicketAction
  panggil SpkService via contract; ticket.spawned_spk_id nullable FK).
- **Consumed by (Phase 4):** SPK (SPK ticket_id backlink — GenerateSpkFromTicketAction
  reads ticket for pre-fill). Reporting (technician performance dari
  ticket history + FRT + SLA + rating).
- **Cross-module:** SpawnSpkFromTicketAction di Modules/Ticketing/Actions/
  panggil SpkService::createFromTicket (service contract). SpkService
  return WorkOrder, ticket.spawned_spk_id set. Allowed (orchestrator
  action, like CompleteSpkAction).
