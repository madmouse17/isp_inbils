# Pattern B sorting — frontend binding (t_066845e4)

Date: 2026-07-29  
Parent: t_a90eb7ed (backend sort foundation)  
Matrix: `docs/plans/2026-07-27-pattern-b-remaining-sorting-matrix.md`

## Done (this card)

Wired **sortable columns + `useServerTable` + `DataTable` `onSort`** on the only remaining non-empty Index consumers that had backend sort allowlists but raw `<Table>` UI:

| Page | Route | Sortable keys (frontend `key` = backend allowlist) | Query params |
|------|-------|-----------------------------------------------------|--------------|
| `resources/js/Pages/Admin/Tickets/Index.tsx` | `admin.tickets.index` | `ticket_number`, `subject`, `category`, `priority`, `status` | `sort`, `direction` (+ existing `search`/`status`/`priority`/`category`) |
| `resources/js/Pages/Admin/NetworkAssets/Index.tsx` | `admin.network-assets.index` | `code`, `name`, `type`, `ip_address`, `location`, `status` | `sort`, `direction` (+ existing `search`/`type`/`status`) |

### Contract used (disk source of truth)

- Hook: `resources/js/hooks/useServerTable.ts`  
  - Required `baseUrl`  
  - Emits `sort` + `direction` (not `sort_by` / `sort_dir`)  
  - `onSort(field, direction)` → visit with `only` partial reload  
- Composite: `resources/js/Components/composite/DataTable.tsx`  
  - Props: `sortKey`, `sortDirection`, `onSort`, `onPageChange`, `pagination`, `filters`, `emptyText`  
  - Column: `sortable?: boolean`  
- Backend (already shipped on parent):  
  - Tickets: `ticket_number|subject|category|priority|status|created_at`  
  - Network assets: `code|name|type|status|ip_address|mac_address|location|created_at`

### Deliberate non-sortable columns

- Tickets: `customer`, `assignee`, `actions` (no direct allowlist column / relation sort not in UI scope)  
- Network assets: `brand` (display combines brand+model; not a single allowlist key), `actions`  
- `created_at` allowlisted on both backends but **not shown** on these Index tables → not bound

## Unsupported / out of scope this card

| Path | Reason |
|------|--------|
| Customers / Users / Invoices / Products / Services / Packages / Payments / Stock / StockMovements / WorkOrders Index | On **this branch disk**, still Pattern A raw `Table` (or empty stubs). No `DataTable` consumer to bind. Backend may already accept sort — frontend migration is a separate card. |
| `created_at` sort on Tickets / NetworkAssets | Backend yes; column not rendered on Index. |
| `mac_address` on NetworkAssets | Backend yes; column not rendered on Index. |
| Export sort parity | Controllers already pass same sort into export query; UI ExportMenu not part of these pages yet. |

## Manual QA

1. `/admin/tickets` — click Number / Subject / Category / Priority / Status headers; URL gains `sort=` + `direction=`; arrow toggles asc/desc; page resets to 1.  
2. Same with active filters; filters preserved.  
3. `/admin/network-assets` — Code / Name / Type / IP / Location / Status.  
4. Dark mode: header buttons remain readable (`hover:text-foreground`, focus ring).  
5. Keyboard: sort control is a focusable `<button>` in `DataTable`.

## Notes for reviewer

- Implementation written via PHP to the real Windows FS (Hermes `read_file` sometimes showed a divergent overlay earlier in the session; final disk proof: `git status` shows both files modified; PHP+PS md5 matched after write).  
- Replaced raw Table markup with `DataTable` while preserving controller prop shapes (`ticket_number`, filters.search, etc.).  
- Create actions use `window.location.href = route(...)` to avoid inventing router imports beyond what filters need; can switch to `router.get` if preferred.

## Cleanup

Scratch `_tmp_*` files in repo root from this worker should be deleted before merge (not part of product).
