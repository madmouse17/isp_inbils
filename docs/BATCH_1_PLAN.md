# Batch 1 — Core ERP Foundation Implementation Plan (UPDATED)

> Branch: `batch-1-core-erp-foundation`
> Updated: 2026-07-01
> Reason: Approved library strategy from baginda

## Library Installation Schedule

| Batch | Library | Type | Action |
|-------|---------|------|--------|
| 1B | (none) | — | Already complete. NumberSequence custom. |
| 1C | spatie/laravel-medialibrary | Required | Install. Replace custom document_attachments. Use media table + custom_properties for company_id/uploaded_by/category. |
| 1C | spatie/laravel-query-builder | Required | Install. Server-side filtering/sorting for new admin pages. |
| 1C | @tanstack/react-table | Required (npm) | Install. Headless table logic for ServerDataTable component. |
| 1D-1E | (none) | — | Notification + Approval are custom. |
| 1F | barryvdh/laravel-debugbar | Dev only | require-dev. Disabled in production. |
| 1F | spatie/laravel-backup | Recommended | Minimal config. |
| 1F | spatie/laravel-health | Recommended | Basic checks (DB, cache, storage). |

### Explicitly NOT added now
laravel/horizon, laravel/scout, meilisearch, laravel/telescope, laravel/pulse, spatie/laravel-model-states, laravel/reverb, leaflet/react-leaflet, spatie/laravel-data, maatwebsite/excel, barryvdh/laravel-dompdf

## Revised Sub-Batch Scope

### Batch 1A — DONE (committed)
Organization + Employee + Vehicle. 3 tables, 3 models, service, controllers, tests. 29 tests pass.

### Batch 1B — DONE (committed)
NumberSequence. 1 table, 1 model, NumberSequenceService (race-safe). 6 tests pass.

### Batch 1C — REVISED: Document Foundation + Server-Side Table
**Install:**
- `composer require spatie/laravel-medialibrary`
- `composer require spatie/laravel-query-builder`
- `npm install @tanstack/react-table`

**Implement:**
- Publish media migration + config
- Create `document_types` table (business layer: name, code, is_required, expiry_days)
- `DocumentType` model
- `DocumentService` — wraps spatie media: upload(attachedModel, file, type, customProperties), list, delete
- Models that need documents implement `HasMedia` interface + `InteractsWithMedia` trait
- Private disk for sensitive documents (config)
- company_id stored in media custom_properties (not separate table for raw file metadata)
- Authorization: users cannot access media across company
- `ServerDataTable` component (React + @tanstack/react-table + spatie query-builder)
- Apply ServerDataTable to Organization + Employee index pages
- Policy + tests

**Do NOT create:** custom `document_attachments` table for raw file storage (use spatie media table)

### Batch 1D — Notification System (unchanged)
- `notifications` table (custom, user-scoped in-app)
- NotificationService: send, markRead, markAllRead, unreadCount
- HandleInertiaRequests: share unread count
- Topbar notification bell
- Tests

### Batch 1E — Approval Engine (unchanged)
- `approval_requests` table (polymorphic)
- ApprovalService: submit, approve, reject, cancel
- Tests

### Batch 1F — Hardening
- Install barryvdh/laravel-debugbar (require-dev)
- Install spatie/laravel-backup (minimal config)
- Install spatie/laravel-health (basic checks)
- Add all new permissions to seeder
- Update CompanySeeder for new entities
- Route/policy/sidebar validation
- Full test suite pass
- npm run build pass
- Write docs/BATCH_1_FINAL_REPORT.md
