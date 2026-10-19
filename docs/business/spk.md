# Module: SPK (Surat Perintah Kerja) — Installation

> Status: DRAFT v2 (ISP pivot 2026-06-30). Phase 4 eksekusi. Dependency:
> Phase 1 (Core) + Phase 2 (Customer/Service) + Phase 3 (Inventory +
> NetworkAsset). Lokasi module: `Modules/SPK/` (nwidart).
> ISP: SPK = pekerjaan teknisi (installation/maintenance/upgrade/
> relocation). Bukan generic work order.

## Tujuan

Mengelola Surat Perintah Kerja (SPK) untuk teknisi lapangan ISP. SPK
otomatis dibuat dari: new installation (subscription baru), maintenance
(aset/tiket bermasalah), upgrade service (naik paket), relocation
(pindah lokasi service). Workflow: Request → Generate SPK → Kepala Unit
Assignment → Technician → Work Execution → Evidence Upload → Completion
→ Evaluation.

CompleteSpkAction = orchestrator lintas module: consume Inventory stock
(StockService::issue) + install NetworkAsset (NetworkAssetService::install) +
activate ServiceSubscription (SubscriptionService) + create Billing
invoice OTC (CreateInvoiceFromSpkAction).

## User Role

| Role | Hak |
|------|-----|
| admin | full CRUD + assign + complete + cancel + delete |
| manager (Kepala Unit) | full CRUD + assign + complete + cancel (no delete) |
| noc | view + create (from ticket/monitoring) + spawn SPK |
| staff | view + create draft (no assign/complete) |
| technician | view assigned to self + start + upload evidence + complete (limited) |

Kepala Unit = role manager (atau admin). Kepala Unit melakukan final
assignment (system suggest kandidat, Kepala Unit pilih).

## Entity

### WorkOrder (Modules/SPK/Models/WorkOrder.php)
SPK. Type ISP (installation/maintenance/upgrade_service/relocation).
Customer + subscription + location + technician (assignee). Status
workflow configurable. Work duration (started_at + completed_at). Result
(notes + status completion).

### WorkOrderItem (Modules/SPK/Models/WorkOrderItem.php)
Line item material/equipment consumed. Product (consumable) reference +
quantity_used. Item tidak mereservasi stok; stok keluar saat approve.
Untuk asset installed, reference via NetworkAssetInstallation
(network_asset_id), bukan WorkOrderItem (WorkOrderItem = consumable only).

### WorkOrderAssignment (Modules/SPK/Models/WorkOrderAssignment.php)
Assignment history. Append-only. Catat: technician (assignee), assigned_by
(Kepala Unit), assigned_at, unassigned_at, reason. Untuk track perubahan
assignment + re-assignment. 1 SPK bisa multiple assignment history (re-
assign), 1 active at a time.

### WorkOrderEvidence (Modules/SPK/Models/WorkOrderEvidence.php)
Evidence upload (foto/dokumentasi pekerjaan). Multiple per SPK. Type
(photo/document), file path, caption, uploaded_by, uploaded_at. Wajib
sebelum completion (app guard: complete reject jika no evidence).

## Database Relation

```
companies ──< work_orders
customers ──< work_orders (FK customer_id nullable — wajib jika type installation/upgrade/relocation)
service_subscriptions ──< work_orders (FK subscription_id nullable — wajib jika type installation/upgrade/relocation)
locations ──< work_orders (FK location_id — work/installation location)
users ──< work_orders (FK created_by, assigned_to active)
work_orders ──< work_order_items (cascade)
products ──< work_order_items (FK product_id nullable — consumable)
work_orders ──< work_order_assignments (cascade)
users ──< work_order_assignments (FK technician_id, assigned_by)
work_orders ──< work_order_evidence (cascade)
users ──< work_order_evidence (FK uploaded_by)
tickets ──< work_orders (FK ticket_id nullable — spawned from ticket)
network_asset_installations ──< work_orders (FK spk_id — backlink dari asset install)
invoices ──< work_orders (FK work_order_id nullable — invoice from SPK)
```

Skema: `docs/DATABASE.md` Section 9 (SPK).

## Entity Fields

### work_orders
- id, company_id FK NOT NULL, code NOT NULL, type enum
  (installation/maintenance/upgrade_service/relocation) NOT NULL, title
  NOT NULL, description text nullable, status enum
  (draft/generated/assigned/in_progress/waiting_review/completed/rejected/
  cancelled) NOT NULL default 'draft', customer_id nullable FK→customers.id
  (restrict — wajib jika type != maintenance-internal), subscription_id
  nullable FK→service_subscriptions.id (restrict — wajib jika type=
  installation/upgrade/relocation), location_id nullable FK→locations.id
  (restrict — installation/work location), assigned_to nullable FK→users.id
  (restrict — active technician, null jika draft/generated), ticket_id
  nullable FK→tickets.id (restrict — spawned from ticket), source enum
  (manual/ticket/subscription/monitoring) NOT NULL default 'manual',
  priority enum(low/medium/high/urgent) NOT NULL default 'medium',
  scheduled_date date nullable, en_route_at timestamp nullable, started_at
  timestamp nullable, submitted_at timestamp nullable,
  completed_at timestamp nullable, result text nullable, rejection_reason
  text nullable, created_by FK→users.id NOT NULL, created_at, updated_at,
  deleted_at.
- Unique: `(company_id, code)`. Index: `(company_id, status)`,
  `(company_id, type)`, `(company_id, assigned_to)`,
  `(company_id, customer_id)`, `(company_id, subscription_id)`,
  `(company_id, ticket_id)`. Trait `BelongsToCompany`. Soft delete.
- Check: `type IN ('installation','maintenance','upgrade_service',
  'relocation')`, `status IN ('draft','generated','assigned','in_progress',
  'waiting_review','completed','rejected','cancelled')`.
- Status configurable: enum values di Setting `spk.statuses` json (v2
  admin add custom status). v1: 8 status hardcoded.

### work_order_items
- id, company_id FK NOT NULL, work_order_id FK NOT NULL (cascade),
  product_id FK NOT NULL (restrict — consumable), quantity_reserved
  decimal(15,2) default 0 (warisan, tidak ditulis lagi: SPK tidak mereservasi stok),
  quantity_used decimal(15,2) default 0,
  note nullable, created_at, updated_at.
- Unique: `(work_order_id, product_id)`. Index: `(company_id, product_id)`.
  Trait `BelongsToCompany`.

### work_order_assignments
- id, company_id FK NOT NULL, work_order_id FK NOT NULL (cascade),
  technician_id FK→users.id NOT NULL (restrict), assigned_by FK→users.id
  NOT NULL (restrict — Kepala Unit), assigned_at timestamp NOT NULL,
  unassigned_at timestamp nullable (null = active), unassigned_by nullable
  FK→users.id, reason varchar nullable, created_at, updated_at.
- Index: `(company_id, work_order_id)`, `(company_id, technician_id)`,
  `(work_order_id, unassigned_at)`. Trait `BelongsToCompany`.
- TIDAK soft delete (history immutable).

### work_order_evidence
- id, company_id FK NOT NULL, work_order_id FK NOT NULL (cascade), type
  enum(photo/document) NOT NULL, file_path varchar NOT NULL, original_name
  varchar, mime_type varchar, size_bytes int, caption varchar nullable,
  uploaded_by FK→users.id NOT NULL, uploaded_at timestamp NOT NULL,
  created_at, updated_at.
- Index: `(company_id, work_order_id)`, `(work_order_id, uploaded_at)`.
  Trait `BelongsToCompany`.
- File storage: local disk `evidence/{work_order_id}/` v1 (S3 v2).

## Workflow

Lihat `docs/WORKFLOW.md` Section 6 (SPK). Ringkas state machine:

```
[draft] ──generate──→ [generated] ──assign──→ [assigned] ──start──→ [in_progress] ──submit──→ [waiting_review] ──approve──→ [completed]
   │                        │                        │                      │                       │
   │                        └──cancel──→ [cancelled] └──cancel──→ [cancelled] └──reject work──→ [rejected]──→ [assigned] (rework)
   └──cancel──→ [cancelled]
```

### Transisi & side effect

| Dari | Ke | Trigger | Side effect |
|------|-----|---------|-------------|
| draft | generated | generate | System generate code + auto-fill from source (ticket/subscription). Status=generated. |
| generated/draft | assigned | assign(technician, Kepala Unit) | assigned_to set, WorkOrderAssignment row (unassigned_at=null), AuditLog. Tidak ada stok yang direservasi. |
| assigned | in_progress | start | started_at=now. |
| assigned | assigned | enRoute (technician, "Berangkat") | en_route_at=now sekali (ulang = 200, tidak berubah), activity `en_route`, notifikasi database ke pelanggan langganan (jika punya login). Status tetap assigned. |
| in_progress | waiting_review | submitForReview (technician, setelah evidence upload) | Status=waiting_review, submitted_at ??= now. Wajib min 1 evidence (app guard). |
| waiting_review | completed | approve (Kepala Unit/admin) | CompleteSpkAction (satu transaksi, SPK dikunci): rekonsiliasi jumlah terpakai dari retur material, consume stok (lihat "Stok pada SPK"), install asset (NetworkAssetService::install if installation), activate subscription (SubscriptionService::activate if installation), create invoice OTC (CreateInvoiceFromSpkAction if Setting spk.auto_invoice or manual trigger). completed_at=now. |
| waiting_review | rejected | rejectWork (Kepala Unit, reason) | rejection_reason set, status=rejected (rework). Technician fix + re-submit. |
| rejected | assigned | reassign/rework | New WorkOrderAssignment row (re-assign). |
| * (except completed/cancelled) | cancelled | cancel(reason) | Status=cancelled. Tidak ada stok yang dilepas, karena SPK tidak mereservasi stok. Permintaan barang yang sudah diserahkan tidak berubah. AuditLog. |

### Item SPK: status yang boleh diubah

Baris item (`work_order_items`) boleh ditambah, diubah, dan dihapus selama SPK belum masuk review. Aturannya satu konstanta, `SpkService::ITEM_EDITABLE_STATUSES`, yang dicerminkan halaman SPK (`ITEM_EDITABLE_STATUSES` di `Admin/SPK/Show.tsx`).

| Status SPK | Label di UI | Item bisa diubah |
|---|---|---|
| draft | Draf | ya |
| generated | Menunggu penugasan | ya |
| assigned | Ditugaskan | ya |
| in_progress | Dikerjakan | ya |
| rejected | Ditolak | ya |
| waiting_review | Menunggu review | tidak ("Item dikunci selama SPK menunggu review.") |
| completed | Selesai | tidak ("SPK selesai, item tidak bisa diubah.") |
| cancelled | Dibatalkan | tidak ("SPK dibatalkan, item tidak bisa diubah.") |

- Di luar status itu server menjawab 422 "Item SPK tidak bisa diubah saat SPK menunggu review, selesai, atau dibatalkan." Status dibaca ulang di dalam kunci SPK, jadi SPK yang pindah status sejak halaman dibuka tetap ditolak.
- Header SPK (judul, deskripsi, pelanggan, langganan, lokasi, prioritas, jadwal) lebih sempit: hanya `draft` dan `generated` (`SpkService::EDITABLE_STATUSES`). Penyimpanannya membawa `expected_updated_at`: bila orang lain sudah menyimpan SPK itu, server menjawab 409 "Data diubah pengguna lain; muat ulang." Halaman web untuk mengubah header belum ada.
- Menambah barang yang sudah ada di SPK mengubah baris itu, tidak membuat baris kedua: satu baris per barang (unik `(work_order_id, product_id)`).
- Hapus item dan rekonsiliasi jumlah terpakai dari retur material berjalan di bawah kunci SPK, seperti penulisan item lainnya.
- Aplikasi teknisi mencatat jumlah terpakai lewat `POST` dan `DELETE /api/v1/technician/work-orders/{wo}/items`, hanya saat SPK `in_progress` dan milik teknisi itu.

### Stok pada SPK

- Item SPK tidak mereservasi stok. Tidak ada request yang menulis `quantity_reserved`, dan kode tidak membaca setting `spk.stock_reservation_mode`. Kolom itu tetap ada dengan nilai awal 0 untuk baris lama.
- Item SPK mengeluarkan stok saat approve. `CompleteSpkAction::consumeStock` menghitung dulu apa yang sudah keluar lewat permintaan barang berstatus `issued` untuk SPK itu (`source_type=work_order`, `source_id`), dengan mengunci SPK, permintaan, lalu itemnya. Bagian yang sudah tercakup dicatat `stock_consumption_skipped_ir_backed` dan tidak dikeluarkan lagi. Hanya sisanya yang dikeluarkan lewat `StockService::issue`.
- Lokasi asal sisa itu: untuk barang yang tercakup permintaan barang, lokasi sumber item permintaan (atau gudang permintaan); untuk barang lain, lokasi SPK. Lihat `docs/business/inventory.md`.
- Reservasi stok hanya ada pada permintaan barang: persetujuan menahan stok, serah terima melepas tahanan lalu mengeluarkan stoknya.

### Tahap kunjungan (timeline)

Satu kunjungan punya empat tahap yang sama di web (kartu "Tahap kunjungan"
di halaman SPK) dan di aplikasi teknisi/pelanggan. Server yang memutuskan
(`Modules/SPK/app/Support/TripSteps.php`); klien hanya menampilkan.

| # | Tahap | Kunci | Waktu | Tercapai saat |
|---|-------|-------|-------|---------------|
| 1 | Ditugaskan | `assigned` | `assigned_at` penugasan aktif | status `assigned` |
| 2 | Dalam perjalanan | `en_route` | `en_route_at` | teknisi menekan "Berangkat" (status tetap `assigned`) |
| 3 | Sedang dikerjakan | `in_progress` | `started_at` | status `in_progress` atau `rejected` |
| 4 | Selesai dipasang | `installed` | `submitted_at` | status `waiting_review` atau `completed` |

- `currentStep` = tahap terakhir yang tercapai; tahap sampai dengan itu
  dianggap tercapai dan hanya tahap itu yang punya waktu. Tahap tercapai
  boleh tanpa waktu (SPK lama, atau teknisi tidak menekan "Berangkat"):
  halaman menulis "waktu tidak tercatat".
- `draft`/`generated`: empat tahap, belum ada yang tercapai.
- `rejected`: kembali ke tahap 3 dan tahap 4 kehilangan waktunya sampai
  diajukan lagi; halaman menandai tahap itu dengan lencana "Perlu perbaikan".
- `cancelled`: tidak ada tahap; halaman menulis "Dibatalkan".
- Waktu tampil dalam format tanggal perusahaan (`Company.settings.date_format`
  + jam).
- `submitted_at` = pengajuan pertama ke `waiting_review` pada kunjungan itu.
  `assign` dengan teknisi lain, atau penugasan ulang setelah `rejected`,
  mengosongkan `en_route_at` dan `submitted_at`: perjalanan dan selesai
  milik satu kunjungan satu teknisi. Penugasan ulang ke teknisi yang sama
  selagi `assigned` mempertahankan perjalanannya.
- Antrean Penugasan (Dispatch) menampilkan lencana "Dalam perjalanan" di
  sebelah petugas saat status `assigned` dan `en_route_at` terisi.
- Tujuan teknisi (`site`) dan jarak check-in diukur ke alamat pemasangan
  pelanggan (`subscription.installationAddress`) bila SPK punya langganan;
  alamat tanpa koordinat berarti jarak `null`, tidak pernah memakai
  koordinat POP. SPK tanpa langganan memakai `location`. `location` (POP /
  gudang) tetap dasar stok. Check-in tiket mengikuti aturan yang sama.

### Type-specific behavior

- **installation**: wajib customer_id + subscription_id + location_id.
  CompleteSpkAction → NetworkAssetService::install (ONT/router at premise)
  + SubscriptionService::activate + CreateInvoiceFromSpkAction (OTC).
- **maintenance**: customer_id + subscription_id + location_id optional
  (bisa internal POP asset). Link network_asset via NetworkAssetInstallation
  (spk_id). CompleteSpkAction → no subscription activate, no invoice OTC
  (maintenance = MRC covered). Consume sparepart stok.
- **upgrade_service**: wajib customer_id + subscription_id. CompleteSpkAction
  → swap ONT asset (remove old + install new) + SubscriptionService update
  package (swap service_package_id) + CreateInvoiceFromSpkAction (OTC
  upgrade fee if applicable).
- **relocation**: wajib customer_id + subscription_id + new location_id.
  CompleteSpkAction → move ONT asset (remove old location + install new
  location) + SubscriptionService update installation_address_id +
  CreateInvoiceFromSpkAction (OTC relocation fee).

### SPK generation (auto from source)
- `GenerateSpkFromTicketAction` (Ticketing spawn): ticket resolved-but-
  needs-field-work OR ticket category device_issue/fiber_issue → spawn
  SPK type=maintenance, pre-fill customer/subscription/asset/location
  from ticket, ticket_id backlink.
- `GenerateSpkFromSubscriptionAction` (Customer create subscription):
  subscription created (pending) → auto-spawn SPK type=installation,
  pre-fill customer/subscription/location (installation_address), source=
  subscription. Kepala Unit assign technician.
- `GenerateSpkFromMonitoringAction` (v2): NOC webhook from monitoring →
  spawn SPK maintenance. v1: NOC manual create.

### Assignment (Kepala Unit)
- System suggest kandidat technician berdasarkan: unit (region/area
  coverage), skill (technician.tags json — fiber/OLT/wireless), workload
  (active SPK count), availability (is_active + no urgent in-progress).
- `SuggestTechnicianQuery` return ranked list. Kepala Unit pilih final
  → assign().
- History: WorkOrderAssignment append-only (track perubahan assignment).

### Status configurable
- v1: 8 status hardcoded enum + CHECK constraint.
- v2: Setting `spk.statuses` json (admin add custom status + transition
  rules). Status transition table `spk_status_transitions` (v2).

## Permission

```
spk.view
spk.create
spk.update
spk.delete
spk.assign          (Kepala Unit)
spk.start           (technician, self-assigned)
spk.submit          (technician, upload evidence + submit for review)
spk.approve         (Kepala Unit — complete)
spk.reject          (Kepala Unit — reject work)
spk.cancel
spk.evidence.upload
spk.evidence.view
spk.export
spk.manage          (super)
```

## API (Route)

Lihat `docs/API.md` Section 6 (SPK group). Ringkas:

- `GET    /admin/spk` (list, filter: type, status, assigned_to, customer, date range)
- `POST   /admin/spk` (create draft)
- `GET    /admin/spk/{wo}` (detail + items + assignments + evidence + linked ticket/subscription/asset/invoice)
- `PUT    /admin/spk/{wo}` (update draft/generated only)
- `DELETE /admin/spk/{wo}` (soft delete draft/cancelled only)
- `POST   /admin/spk/{wo}/generate` (draft→generated)
- `POST   /admin/spk/{wo}/assign` (body: technician_id — Kepala Unit)
- `GET    /admin/spk/{wo}/suggest-technician` (system suggest kandidat)
- `POST   /admin/spk/{wo}/start`
- `POST   /admin/spk/{wo}/items` (add consumable line; barang yang sama mengubah barisnya, status: lihat "Item SPK: status yang boleh diubah")
- `DELETE /admin/spk/{wo}/items/{item}`
- `POST   /admin/spk/{wo}/evidence` (multipart upload)
- `GET    /admin/spk/{wo}/evidence`
- `DELETE /admin/spk/{wo}/evidence/{ev}`
- `POST   /admin/spk/{wo}/submit` (in_progress→waiting_review, require evidence)
- `POST   /admin/spk/{wo}/approve` (waiting_review→completed — triggers CompleteSpkAction)
- `POST   /admin/spk/{wo}/reject` (body: reason — waiting_review→rejected)
- `POST   /admin/spk/{wo}/cancel` (body: reason)
- `GET    /admin/spk/{wo}/assignments` (assignment history)
- `GET    /admin/spk/export`

## Testing Scenario

### Installation SPK full flow
1. Create subscription (pending) → auto-spawn SPK type=installation,
   source=subscription, customer+subscription+location pre-filled.
   Status=generated.
2. Kepala Unit call suggest-technician → ranked list (workload+skill).
   Assign technician T01. Status=assigned, WorkOrderAssignment row.
   Tidak ada stok yang direservasi.
3. Technician start: started_at=now. Status=in_progress.
4. Upload evidence (2 foto instalasi). WorkOrderEvidence rows.
5. Submit for review: status=waiting_review. (Reject if no evidence →
   guard.)
6. Kepala Unit approve: CompleteSpkAction:
   - StockService::issue (consume kabel 20m, qty--; bagian yang sudah
     dikeluarkan permintaan barang yang diserahkan untuk SPK ini dilewati).
   - NetworkAssetService::install (ONT installed at premise, status=installed,
     NetworkAssetInstallation spk_id backlink).
   - SubscriptionService::activate (subscription active, ont_asset_id set,
     next_invoice_date set).
   - CreateInvoiceFromSpkAction (invoice OTC 500000, draft, work_order_id
     link). Status=completed, completed_at=now.

### Maintenance from ticket
1. Ticket device_issue, resolved-but-needs-field-work → spawn SPK
   type=maintenance, ticket_id backlink, customer+subscription+asset
   pre-filled.
2. Assign + start + evidence + approve. CompleteSpkAction: consume
   sparepart, NO subscription activate (already active), NO invoice OTC
   (maintenance = MRC). NetworkAsset status set maintenance→installed.

### Validation
1. Submit for review with no evidence → 422 "evidence required".
2. Approve SPK not in waiting_review → 422.
3. Assign draft (not generated) → 422 (must generate first). OR allow
   direct draft→assigned (configurable).
4. Cancel assigned → status cancelled, tidak ada stock movement (SPK tidak
   mereservasi stok).
5. Complete SPK without assigned_to → 422.

### Authorization
1. Technician view assigned-to-self → 200. View assigned-to-other → 403.
2. Technician start/submit self-assigned → 200. Assign → 403.
3. Manager assign/approve/reject → 200. Delete → 403.
4. NOC create from ticket → 200. NOC approve → 403.

### Number race
1. Concurrent create 2 SPK → 2 code beda (SPK-{YEAR}-{NNNNN}).

## Acceptance Criteria

- [ ] 4 type SPK (installation/maintenance/upgrade_service/relocation)
  dengan type-specific CompleteSpkAction behavior.
- [ ] Status state machine 8 status (draft→generated→assigned→
  in_progress→waiting_review→completed, rejected, cancelled).
- [ ] Assignment: system suggest (workload+skill+availability) + Kepala
  Unit final assign + WorkOrderAssignment append-only history (re-assign
  tracked).
- [ ] Evidence upload wajib sebelum submit (app guard).
- [ ] CompleteSpkAction orchestrator: StockService::issue + NetworkAssetService::install
  + SubscriptionService::activate + CreateInvoiceFromSpkAction (if
  installation + auto_invoice).
- [ ] SPK generation auto dari ticket (maintenance) + subscription
  (installation).
- [ ] SPK tidak mereservasi stok; approve mengeluarkan stok hanya untuk bagian yang belum dikeluarkan permintaan barang.
- [ ] Code generation unique + race-safe (SPK-{YEAR}-{NNNNN}).
- [ ] Soft delete draft/cancelled only.
- [ ] Policy per aksi (technician self-limit, Kepala Unit assign/approve).
- [ ] Activity log: create, generate, assign, start, evidence upload,
  submit, approve, reject, cancel, complete.
- [ ] Factory + seeder (20 SPK: 8 installation, 6 maintenance, 3 upgrade,
  3 relocation — mix status).
- [ ] Feature test ≥ 80% coverage.
- [ ] UI: SPK list (filter type/status/technician/date), detail (items +
  assignment timeline + evidence gallery + linked entities + lifecycle
  buttons), form, technician dashboard (my SPK + start/submit), Kepala
  Unit review queue (waiting_review list + approve/reject).
- [ ] UI pakai Components/ui + composite.
- [ ] Dark mode + responsive.

## Module Dependencies

- **Depends on (Phase 1):** Core (Company, User, Role, Permission,
  ActivityLog, Setting), Location (shared Core — work location).
- **Depends on (Phase 2):** Customer/Subscription (shared Core —
  customer_id + subscription_id direct FK; SubscriptionService::activate
  called by CompleteSpkAction).
- **Depends on (Phase 3):** Inventory (StockService::issue via service
  contract, polymorphic reference stock_movements.reference=WorkOrderItem;
  permintaan barang `source_type=work_order` memberi tahu SPK stok mana yang
  sudah keluar), NetworkAsset (NetworkAssetService::install via service
  contract, NetworkAssetInstallation.spk_id backlink).
- **Consumed by (Phase 5):** Billing (CreateInvoiceFromSpkAction reads
  work_order + work_order_items untuk pre-fill invoice OTC; invoice.
  work_order_id backlink), Ticketing (ticket_id backlink — spawn from
  ticket), Reporting (technician performance metrics dari SPK history).
- **Cross-module orchestration:** CompleteSpkAction di Modules/SPK/Actions/
  panggil InventoryService + NetworkAssetService + SubscriptionService
  (shared Core, direct) + BillingService (via service contract or action).
  CompleteSpkAction = satu-satunya Action lintas 4 module — documented
  exception ke dependency rule (allowed karena orchestrator).
