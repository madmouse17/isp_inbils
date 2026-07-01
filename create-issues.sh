#!/usr/bin/env bash
# Create/update GitHub issues for inbils feature checklists
# Run AFTER: gh auth login

set -e

REPO="madmouse17/isp_inbils"

# Issue: Phase 3 — Inventory + NetworkAsset
gh issue create --repo "$REPO" \
  --title "Phase 3 — Inventory + NetworkAsset" \
  --label "phase-3,enhancement" \
  --body "## Phase 3 — Inventory + NetworkAsset

### Inventory Module (Modules/Inventory)
- [x] \`Product\` model + migration (SKU unique per company, category/unit FK, sell/cost/min stock)
- [x] \`Category\` model + migration (self-ref parent, code unique per company)
- [x] \`Unit\` model + migration (name + symbol unique per company)
- [x] \`Stock\` model + migration (product + location combo unique, quantity + reserved_quantity)
- [x] \`StockMovement\` model + migration (immutable, 7 types, balance_after + reserved_after snapshots)

### Stock Management
- [x] \`StockService\` — 7 movement types (receive/issue/transfer/adjustment/reserve/release/return)
- [x] DB transactions + audit logging on all movements
- [x] \`InsufficientStockException\` (reserve/issue exceeds available)
- [x] Multi-location support (transfer = 2 movement rows)
- [x] Reserve/release = reserved_quantity only (no qty change)

### Inventory Frontend
- [x] Products Index (filter category/active/search + pagination)
- [x] Products Create/Edit (form with category/unit select, price, min_stock, active switch)
- [x] Products Show (detail + stock-per-location table + recent movements table)
- [x] Categories Index (inline modal CRUD)
- [x] Units Index (inline modal CRUD)
- [x] Stocks Index (filter + receive/issue/transfer/adjust modals)
- [x] Movements Index (history with type filter + pagination)
- [x] Item Finder (search → product + locations with qty/path/available)

### NetworkAsset Module (Modules/NetworkAsset)
- [x] \`NetworkAsset\` model + migration (serial unique per company, status lifecycle, ownership, vendor/model, location/customer/subscription links)
- [x] \`NetworkAssetInstallation\` model + migration (append-only, 1 active per asset, removed_at on remove/retire)

### Asset Lifecycle
- [x] \`NetworkAssetService\` — install/remove/maintenance/resume/damage/repair/retire
- [x] DB transactions + audit logging on all lifecycle transitions
- [x] Code generation (AST-{YEAR}-{NNNNN}, race-safe with lockForUpdate)
- [x] Installation history (append-only, removed_at set on remove/retire/repair)

### NetworkAsset Frontend
- [x] Index (filter type/status/location/search serial/mac/ip/code)
- [x] Create (form with asset_type, serial, MAC, IP, location, ownership, vendor, model, dates)
- [x] Edit (same form, no status/code)
- [x] Show (detail + installation history timeline + lifecycle action buttons based on status)
- [x] Trace (search by serial/MAC/IP/customer → result cards with location path + status + links)

### Factories + Seeders
- [x] 6 factories: Category, Unit, Product, Stock, StockMovement, NetworkAsset
- [x] CompanySeeder: 5 categories + 10 products + 10 network assets

### Infrastructure
- [x] AdminLayout sidebar: Inventory + Network Assets links (permission-gated)
- [x] TS types: inventory.d.ts + network-asset.d.ts
- [x] AppServiceProvider: 4 new policy registrations
- [x] Build: tsc + vite clean (0 errors)
"

echo "Phase 3 issue created."

# Update roadmap issue — create new one with updated checklist
gh issue create --repo "$REPO" \
  --title "Roadmap Update — Phase 3 Complete, Phase 4-8 Remaining" \
  --label "roadmap" \
  --body "## Roadmap (updated 2026-07-01)

### Completed Phases
- [x] Phase 1 — Core Foundation (Company, Setup Wizard, Auth, User/Role/Permission, Audit, Location, Settings, Dashboard)
- [x] Phase 2 — Master Data (Service Catalog, Customer, Subscription, Frontend, Seeders)
- [x] Phase 3 — Inventory + NetworkAsset (Product/Category/Unit/Stock/StockMovement, NetworkAsset/Installation, 7-type stock movement, asset lifecycle, trace, 14 frontend pages)

### Phase 4 — SPK (Surat Perintah Kerja)
- [ ] 4 SPK types (installation/maintenance/upgrade/relocation)
- [ ] 8-state machine (draft→generated→assigned→in_progress→waiting_review→completed, rejected, cancelled)
- [ ] Assignment with suggestions (workload+skill+availability)
- [ ] CompleteSpkAction orchestrator (IssueStockAction + InstallNetworkAssetAction + SubscriptionService::activate)
- [ ] Evidence upload
- [ ] PDF print
- [ ] Code generation (SPK-{YEAR}-{NNNNN})

### Phase 5 — Billing
- [ ] Recurring MRC job (daily schedule, catch billing_day)
- [ ] One-time OTC from SPK
- [ ] Invoice state machine (draft→sent→partial→paid, overdue, cancelled)
- [ ] Payment (immutable, cancel+reverse only)
- [ ] Overdue + auto-suspend job
- [ ] Tax (PPN 11%)
- [ ] PDF invoice (barryvdh/laravel-dompdf)
- [ ] Code generation (INV-{YEAR}-{NNNNN})

### Phase 6 — Ticketing
- [ ] 5-state machine (open→assigned→on_progress→resolved→closed)
- [ ] SLA + breach detection
- [ ] Auto-routing suggest
- [ ] Spawn SPK from ticket
- [ ] Comment + attachment
- [ ] Code generation (TKT-{YEAR}-{NNNNN})

### Phase 7 — Performance
- [ ] Employee evaluation CRUD
- [ ] Polymorphic reference (WorkOrder/Ticket)
- [ ] Snapshot FRT/resolution

### Phase 8 — Reporting
- [ ] 7 report types
- [ ] Charts (recharts)
- [ ] Audit log cross-tenant filter
- [ ] Excel export

### v1.0.0 Release
- [ ] All phases complete
- [ ] Production config
- [ ] Tag v1.0.0
"

echo "Roadmap issue created."
echo "All issues created successfully!"
