# Master data lifecycle: deactivate instead of delete, restore, frozen history

Status: ready-for-agent
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `master-lifecycle`
Source: owner 2026-10-17 (flow-audit follow-up): "riwayat tidak boleh hilang karena data perusahaan … kalau diupdate semua data paket layanan lama ikut berubah harusnya tidak boleh, itu berlaku semua data penting yang untuk report … jadikan soft delete dan harus dimunculkan biar bisa diaktifkan lagi, dan usahakan ada filternya kalau ada aktif maupun nonaktif dan status lainnya kasih filter". Evidence: `local://delete-matrix.md` (45 entities) and flow-audit finding F2-D04.

## Owner decisions (all accepted 2026-10-17)

1. Master records are never hard-deleted while referenced. "Hapus" on a referenced row becomes "Nonaktifkan" (`is_active = false`); the dialog names how many children reference it. Hard delete stays only for rows that were never referenced, checked with `withTrashed()` children inside a transaction holding `lockForUpdate()` on the row, and backed by the existing `restrictOnDelete` FKs (the hard delete path lets MySQL catch a concurrent child insert).
2. Soft-deleted rows remain visible: every index page of a soft-deletable entity gets a status filter with the values the entity has (`aktif`, `nonaktif`, `dihapus`, plus its own lifecycle statuses where they exist, e.g. subscriptions/tickets/SPK) and an "Semua" option. Default view = not deleted. Deleted rows get a "Pulihkan" action (same permission as delete); restoring into a code/unique conflict is a 422 with an Indonesian message.
3. Price- and report-relevant master changes are versions, not edits: service packages get `valid_from`-style versions (price_mrc, price_otc, bandwidth/speed/SLA); a subscription points at the version it was sold under; migrating a subscription to a new version is an explicit admin action, logged. Renaming (name/description) stays in place.
4. Financial and closed documents are frozen at issue: `invoices` snapshot `customer_name`, `customer_address`, `customer_tax_id`, `package_name` when they leave draft; the PDF and the API read the snapshot. Completed SPK snapshot technician name + location code; closed tickets snapshot category name + assignee name.
5. Aggregated reports group by the snapshot/version (package/category name on the subscription or invoice line, organization unit on the SPK), not by the live parent row. Existing rows are backfilled once from the current live values.
6. Every relation shown in a history view uses `withTrashed()` so an old row never renders as "-" after its parent was deleted.

## Entities in scope (from the matrix)

Masters with `is_active` + SoftDeletes: service packages, bandwidth profiles, speed profiles, SLA tiers, products, inventory categories, units, locations (POP/warehouse/site/area), network assets, document types, customers + addresses, employees/vehicles/organization units where soft-deletable. Transactional soft-deletables that only need the filter + Pulihkan: tickets, SPK, invoices (draft), evaluations. Hard-delete-only entities keep their current guards (package categories, VLAN, IP prefix, ODN, users, roles).

## Non-goals

- No change to payments, stock movements, activity log (immutable already).
- No new "archive" tables; soft delete + versions only.
- Mobile apps unchanged except where the API payload gains snapshot fields (additive).

## Tickets

01-design (architect) · then slices per the design document.
