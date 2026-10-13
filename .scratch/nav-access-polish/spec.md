# Navigation and access polish (follow-up to the sidebar regroup, 2026-10-12)

Status: done
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `nav-access-polish` (owner request 2026-10-12: "jadikan specs / ticket matpocok lalu implement ... kerjakan semua")
Source: the eight improvement points reported after commit `1df409c` (sidebar regrouped by domain).

> Owner delegated every design decision to the assistant ("anda sebagai saya"). The decisions below are the assistant's, recorded so they can be reviewed and reversed.

## Owner decisions (taken by the assistant on the owner's behalf)

1. **Q1 — Language of the navigation.** The sidebar mixes English and Indonesian because the destination pages do. (a) Bahasa Indonesia for every sidebar group title and item label, and the same text as the `PageHeader`/`AdminLayout` title of each destination page, so label and heading always match. Page body copy is not translated in this lane. (b) English everywhere in the admin. (c) Leave mixed.
   **Decision (a).** The owner chose Indonesian for user-facing text before (notifications, customer portal, My Work, Receivables); staff, technicians and customers read Indonesian. Tradeoff: page body text stays partly English until a full localisation lane.
2. **Q2 — Gate of the Permissions page.** It opens with `users.manage` while Roles needs `roles.manage`; the page is the reference list for role editing. (a) Gate it with `roles.manage` (route, controller and sidebar). (b) Allow either. **Decision (a).** Tradeoff: a custom role holding only `users.manage` loses the read-only list; every seeded role holding `users.manage` also holds `roles.manage`.
3. **Q3 — Permission names that break the `<area>.<action>` grouping.** (a) Rename `user.two_factor.reset` → `users.two_factor.reset` and `document.export` → `system.document.export` with a reversible data migration (role grants keep their permission ids). (b) Keep the names and the editor's companion mapping. **Decision (a).**
4. **Q4 — Searching the role permission editor.** (a) A search field above the category list filters permissions by name or label across all categories; clearing it restores the category view; "select all" in a filtered view only touches the shown permissions. **Decision (a).**
5. **Q5 — Technician Performance link answers 403 for a manager without an organization.** (a) The Reporting module shares a server-computed `canTechnicianReport` boolean (same rule as `TechnicianReportAccess`), and the sidebar item requires it on top of `report.technician`. Shared from the module's provider, not from Core, to respect the Core → Module boundary. **Decision (a).**
6. **Q6 — Mobile drawer accessibility.** (a) Closed drawer is `inert`; Escape closes it; focus moves into the drawer on open and back to the toggle on close; Tab stays inside while open; `<nav aria-label>` on the sidebar landmarks. **Decision (a).**
7. **Q7 — Repeated icons.** (a) One distinct heroicon per sidebar item (no new dependency). **Decision (a).**
8. **Q8 — Leftover `public/.build-root-owned-20261012`.** (a) Delete it and its line in `.git/info/exclude`. **Decision (a).**

## Translations (Q1)

Groups: Dashboard → **Dasbor**; Operations → **Operasional**; Customers & Services → **Pelanggan & Layanan**; Network → **Jaringan**; Inventory → **Inventaris**; Finance & Reports → **Keuangan & Laporan**; Administration → **Administrasi**.

Items (old → new): Dashboard → Dasbor; Pekerjaan Saya; Dispatch → Penugasan; Approval → Persetujuan; SPK; Ticketing → Tiket; Evaluations → Evaluasi; Customers → Pelanggan; Service → Paket Layanan; Bandwidth Profiles → Profil Bandwidth; Speed Profiles → Profil Kecepatan; SLA Tiers → Tingkat SLA; Locations → Lokasi; Network Assets → Aset Jaringan; NAS Routers → Router NAS; IP Addresses → Alamat IP; ODN; Permintaan Barang; Inventory → Barang; Categories → Kategori; Units → Satuan; Stocks → Stok; Item Finder → Cari Barang; Stock Movements → Mutasi Stok; Billing → Tagihan; Tunggakan; Tagihan Saya; Reports → Laporan; Technician Performance → Kinerja Teknisi; Sales KPI → KPI Penjualan; Consolidated Metrics → Metrik Konsolidasi; Company → Perusahaan; Organization → Organisasi; Employees → Karyawan; Vehicles → Kendaraan; Users → Pengguna; Roles → Peran; Permissions → Hak Akses; Documents → Dokumen; Number Sequences → Penomoran; Komponen.

## Out of scope

Translating page body copy, buttons, tables and validation messages; new destinations in the sidebar; any permission semantics beyond Q2/Q3/Q5.

## Testing decisions

- Sidebar: `navigation.selfcheck.mjs`, `tests/Feature/fixtures/sidebar-destinations.json`, `AdminMenuAuthorizationTest` (every role × destination) and the E2E specs that name sidebar links or page headings.
- Q2/Q3/Q5: feature tests through HTTP (403/200, shared prop values per role/organization case), migration up/down on `inbils_test`, `RolePermissionSeederTest`.
- Q4/Q6: `permissionGroups.selfcheck.mjs` for filtering; an E2E or browser check for search and the drawer (Escape, focus return, closed drawer not tabbable).
- Gates: Pint, Larastan, `tsc`, ESLint on touched files, `npm run build`, focused PHPUnit, canonical E2E; security review (permissions and authorization change).

## Tickets

01-ui-navigation-indonesian-icons · 02-backend-permissions-page-gate · 03-backend-permission-renames · 04-ui-role-editor-search-and-drawer-a11y · 05-backend-technician-report-flag · 06-chore-remove-leftover-build · 07-qa-review-release
