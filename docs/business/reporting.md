# Module: Reporting

> Status: DRAFT v2 (ISP pivot 2026-06-30). Phase 8 eksekusi (terakhir,
> lapisan read-only). Dependency: SEMUA module (Phase 1-7). Lokasi module:
> `Modules/Reporting/` (nwidart). Keputusan D1: Reporting = READ-ONLY
> module (Query classes only, NO models, NO write). EmployeeEvaluation
> (write entity) = cross-cutting app/Core, lihat `docs/modules/performance.md`.

## Tujuan

Laporan performa teknisi + unit + metrik bisnis ISP. READ-ONLY — hitung
dari event/history (work_orders, tickets, network_asset_installations,
stock_movements, invoices, service_subscriptions, employee_evaluations),
BUKAN data statis. Laporan bisa dibuat ulang untuk periode manapun.
Export Excel + PDF.

Prinsip: "Jangan simpan nilai performa sebagai data statis. Hitung dari
workflow history, assignment history, evaluation. Simpan event/history
agar laporan bisa dibuat ulang." (DATABASE RULE, user spec).

## User Role

| Role | Hak |
|------|-----|
| admin | view all reports + export |
| manager | view all reports + export (unit/team scope) |
| noc | view NOC-relevant reports (asset, ticket, SLA) |
| staff | view limited (billing/customer summary) |
| technician | view own performance report only |

## Reports (ISP)

### 1. Technician Performance (per teknisi)
- **Input:** technician_id (user), date range.
- **Output (TechnicianPerformanceQuery):**
  - Jumlah SPK selesai (work_orders status=completed, assigned_to=
    technician, completed_at in range).
  - Jumlah ticket selesai (tickets status in (resolved,closed),
    assigned_to=technician, resolved_at in range).
  - Rata-rata waktu penyelesaian SPK (avg completed_at - started_at).
  - Rata-rata waktu penyelesaian ticket (avg resolved_at - created_at).
  - SLA compliance ticket (% resolved within sla_deadline).
  - Rating customer (avg employee_evaluations.score where reference_type=
    Ticket, evaluator=customer OR rating field; OR customer_rating field
    di ticket v2).
  - First response time (avg tickets.first_response_at - created_at).
  - Workload (active SPK + ticket count saat query).
  - Breakdown by SPK type (installation/maintenance/upgrade/relocation).
  - Breakdown by ticket category (no_internet/slow/packet_loss/device/
    fiber).
- **Peringkat (tanpa teknisi dipilih, F9-U02).** Halaman Kinerja Teknisi tidak lagi menunggu satu teknisi: filter "Semua teknisi" mengisi tabel "Peringkat teknisi" untuk semua teknisi yang boleh dipilih pengguna. Urutannya jumlah SPK selesai + tiket selesai pada periode; seri berbagi peringkat dan diurut menurut nama. Kolom: Peringkat, nama, SPK selesai, tiket selesai, kepatuhan SLA (kosong bila tanpa tiket selesai), rata-rata respons pertama, nilai evaluasi rata-rata dan beban aktif. Satu query berkelompok per ukuran, bukan satu per teknisi (`TechnicianPerformanceQuery::ranking`).

### 2. Unit Performance (per unit/team)
- **Input:** unit (region/area OR role-group NOC/field/billing), date
  range.
- **Output (UnitPerformanceQuery):**
  - Produktivitas unit (total SPK + ticket resolved by unit members).
  - Beban kerja (active workload per member, avg).
  - Performa anggota (list technician with metrics from #1).
  - SLA compliance unit (% tickets resolved within SLA).
  - Response performance (avg FRT per member).
  - Comparison ranking (member vs unit avg).

### 3. Business Metrics (manager/admin)
- **Input:** date range.
- **Output (BusinessMetricsQuery):**
  - MRR (Monthly Recurring Revenue) = sum active service_subscriptions
    .mrc_amount.
  - Active subscriptions count (by status, by package, by region).
  - Churn rate (terminated subscriptions in period / active at start).
  - New subscriptions in period (by source: SPK installation).
  - Revenue (invoices paid in period, by type recurring/one_time).
  - Outstanding (invoices sent+overdue, total unpaid).
  - Suspend rate (subscriptions suspended in period).
  - SLA compliance overall (% tickets resolved within SLA).
  - Asset utilization (network_assets status distribution: available/
    installed/maintenance/damaged/retired).
  - Installation throughput (SPK type=installation completed in period,
    avg completion time).

### 4. Asset Utilization (NOC/admin)
- **Input:** location_id (POP/region) OR asset_type, date range.
- **Output (AssetUtilizationQuery):**
  - Asset status distribution (available/installed/maintenance/damaged/
    retired).
  - Asset per location (count by POP/rack).
  - Installation history count (network_asset_installations in range).
  - Asset downtime (maintenance + damaged duration, sum).
  - Customer-linked assets (ONT installed at premise count).
  - Retired assets in period.

### 5. SLA Compliance (NOC/manager)
- **Input:** date range, category filter.
- **Output (SlaComplianceQuery):**
  - % resolved within SLA (overall + per category).
  - Avg resolution time (per category).
  - Avg first response time (per category).
  - Breach count + list (tickets sla_deadline < resolved_at OR breach
    flag).
  - Trend (monthly breakdown).
  - Jumlah tiket selesai dipecah per penangan (`by_technician`), prioritas (`by_priority`, urut urgent, high, medium, low) dan sumber target (`by_source`: nama tingkat SLA, atau "Target kategori tiket" untuk tiket tanpa tingkat). Tiap pecahan memberi total, sesuai SLA, melanggar, persentase dan rata-rata respons pertama; baris-barisnya berjumlah `total_resolved`.
  - Kepuasan pelanggan (CSAT): `csat_average` (rata-rata nilai 1 sampai 5, satu desimal) dan `csat_count` (jumlah penilaian), dari `tickets.customer_rating`. Mengikuti hari penilaian (`customer_rated_at`), bukan hari tiket selesai; tiket yang belum dinilai tidak dihitung dan tidak bernilai nol. Halaman menulis "Kepuasan pelanggan", angkanya dengan "dari 5" dan jumlah penilaian, atau "Belum ada penilaian pada periode ini."
  - Pelanggaran terlama (`violators`, di luar `execute()`): sepuluh tiket selesai yang paling lama melewati `sla_deadline`, dengan kode, judul, kategori, penangan, prioritas dan menit terlambat. Hanya untuk yang boleh melihat daftar tiket (penangan hanya melihat tiketnya sendiri); selain itu `null` dan kartunya tidak tampil.
  - Penangan dalam pecahan per teknisi: pemegang `report.manage` melihat semua nama. Pengguna lain hanya membaca nama teknisi dalam cakupan laporan Teknisi mereka, dan semua penangan di luar cakupan digabung menjadi satu baris "Penangan lain", jadi jumlah baris tetap sama dengan tiket selesai. Tiket tanpa penangan tetap satu baris "Belum ditugaskan".

### 6. Stock Card (admin/manager) — Inventory cross
- **Input:** product_id, location_id, date range.
- **Output:** stock movement history (receive/issue/transfer/adjustment/
  reserve/release/return) with balance_after snapshot + reference
  (SPK/invoice).
- Tanpa produk dipilih, halaman menulis "Barang terakhir bergerak": delapan barang yang terakhir punya pergerakan, satu query.

### 7. Audit Log Report (admin)
- **Input:** user, module, date range.
- **Output:** activity_log entries (subject, causer, event, properties
  before/after).
- Baris log `support` (tindakan tim Inbils pada tenant) ditulis satu kalimat per kode oleh `App\Support\SupportActionText` ("Tim dukungan Inbils mengatur ulang 2FA {nama}"). Kalimat itu dipakai `AuditLogResource` (`support_text`), pencarian teks (kode yang kalimatnya memuat istilah, atau subjek anggota yang namanya cocok) dan `ReportExporter`, jadi satu kode ditulis sekali. Kode yang belum dikenal ditulis "Akses dukungan oleh tim Inbils".
- Kalimat baris audit ditulis server oleh `App\Support\AuditEventText` (pasangan `log_name` + `description`, dengan kata benda log dan kata kerja deskripsi bila belum ada kalimat khusus; pasangan yang tidak dikenal ditulis "{nama log}: {deskripsi}" dengan garis bawah menjadi spasi). Halaman Log Audit (`text`, `subject_label`), pencarian teks, ekspor, aktivitas terbaru di dashboard dan bagian aktivitas halaman perusahaan di panel platform memakai kalimat yang sama; kamus di sisi browser dihapus. Satu zona waktu: milik perusahaan.

## Ekspor CSV dan PDF (K32, K33)

- Semua ekspor lewat `App\Support\ExportQuery`. CSV selalu satu baris judul lalu data mentah. PDF memakai `resources/views/exports/table.blade.php` (A4 lanskap) dengan kop: logo perusahaan (disematkan ke berkas), nama perusahaan, judul laporan, "Periode: ...", "Filter: ..." dan "Dicetak {tanggal} oleh {nama}", lalu nomor halaman "Halaman {n} dari {total}" di kaki setiap halaman. Kolom bertipe ditulis seperti di halaman: uang "Rp 1.250.000", tanggal "18 Okt 2026", durasi "2 j 15 m". Font DomPDF disubset, jadi satu berkas tidak lagi membawa seluruh font DejaVu (sekitar 880 KB).
- Isi ekspor memakai kata yang sama dengan halaman: jenis pergerakan stok, satuan, status, nama teknisi (bukan id) dan durasi (`Modules/Reporting/app/Exports/ExportLabels.php`). Batas baris mengikuti `config('exports.max_rows')`; PDF memakai batas sel, jadi tabel lebar memuat lebih sedikit baris, dan PDF yang terpotong menulis "PDF dibatasi {n} baris. Untuk data lengkap, unduh CSV atau persempit periode."
- Ekspor tunggakan: `GET admin.billing.receivables.export` (izin `billing.export`, `format=csv|pdf`) mengikuti filter tab "Per tagihan" (`search`, `bucket`, `due_from`, `due_to`). Kolom: Nomor tagihan, Pelanggan, Kode pelanggan, Tanggal terbit, Jatuh tempo, Lewat jatuh tempo (hari), Umur, Total, Terbayar, Sisa, Status. Tombolnya "Ekspor" di halaman Tunggakan, dengan pilihan "Ekspor CSV" dan "Ekspor PDF".

## Konsolidasi (flow-audit-fixes gelombang 3)

- Laporan Konsolidasi hanya ada di perusahaan induk. Pemegang `report.consolidated.view` yang membukanya dari anak perusahaan dialihkan ke halaman Laporan dengan peringatan "Metrik Konsolidasi hanya tersedia di perusahaan induk {induk}. Ganti perusahaan lewat pemilih di menu samping." dan, bila boleh berpindah ke induk, tombol "Pindah ke {induk}" yang memakai pengalih perusahaan yang sama dengan menu samping. Tanpa izin itu, penolakannya tetap 403.

## Architecture (Query classes only, NO models)

```
Modules/Reporting/
  Http/
    Controllers/
      ReportController.php         (dispatch by report type)
  Queries/
    TechnicianPerformanceQuery.php
    UnitPerformanceQuery.php
    BusinessMetricsQuery.php
    AssetUtilizationQuery.php
    SlaComplianceQuery.php
    StockCardQuery.php
    AuditLogQuery.php
  Resources/
    TechnicianPerformanceResource.php
    UnitPerformanceResource.php
    BusinessMetricsResource.php
    (etc — report DTOs)
  Routes/
    web.php
  Tests/
```

- NO Models/ dir (read-only, no write).
- Query classes = complex DB queries (join + agregat + filter date range).
  Boleh >30 baris (exception ke "query >30 baris buang ke Queries" —
  ini memang Queries).
- Resource = report DTO (bukan entity Resource).
- Export: Excel (maatwebsite/excel) untuk tabular, PDF (dompdf) untuk
  single-record summary.

## Database Rule (MANDATORY)

- TIDAK ada tabel `technician_performance` / `unit_metrics` / `business_
  metrics` storing static computed values.
- Semua laporan hitung REAL-TIME dari:
  - `work_orders` + `work_order_assignments` + `work_order_items` +
    `work_order_evidence` (SPK history).
  - `tickets` + `ticket_comments` (ticket history + FRT + resolution).
  - `network_assets` + `network_asset_installations` (asset + install
    history).
  - `stock_movements` (stock card).
  - `invoices` + `payments` (revenue + outstanding).
  - `service_subscriptions` (MRR + active count + churn).
  - `employee_evaluations` (rating + score — lihat performance.md).
  - `activity_log` (audit trail).
- Snapshot/materialized view HANYA jika query terlalu berat (v2: job
  harian compute + cache table `report_snapshots` json). v1: real-time
  query + DB index optimasi.

## Permission

```
report.view
report.technician            (own + team)
report.unit
report.business
report.asset
report.sla
report.stock_card
report.audit_log
report.export
report.manage                (super)
```

## API (Route)

Lihat `docs/API.md` Section 10 (Reporting group). Ringkas:

- `GET    /admin/reports/technician` (query: technician_id, date_from, date_to)
- `GET    /admin/reports/technician/{user}` (detail per teknisi)
- `GET    /admin/reports/unit` (query: unit/region, date range)
- `GET    /admin/reports/business` (query: date range)
- `GET    /admin/reports/asset` (query: location_id/asset_type, date range)
- `GET    /admin/reports/sla` (query: category, date range)
- `GET    /admin/reports/stock-card` (query: product_id, location_id, date range)
- `GET    /admin/reports/audit-log` (query: user, module, date range)
- `GET    /admin/reports/{type}/export` (Excel/PDF, same filters)

## Testing Scenario

### Technician performance
1. Technician T01: 5 SPK completed (3 installation, 2 maintenance) in
   July. Avg completion 2h. 8 tickets resolved, 7 within SLA (87.5%).
   Avg FRT 15min. Customer rating avg 4.5/5.
2. Run TechnicianPerformanceQuery(T01, 2026-07-01..2026-07-31) → return
   metrics above + breakdown by type/category.
3. Re-run for August → different result (real-time, no static).

### Unit performance
1. Unit "Field Jakarta" (5 technician). Query → aggregate per member +
   unit avg + ranking. T01 top (8 SPK), T05 bottom (2 SPK).

### Business metrics
1. Query July → MRR=sum active subs mrc (e.g. 12 active × 250000=
   3,000,000). Churn 2/14=14%. New 3 (from SPK installation). Revenue
   paid 4,500,000. Outstanding 800,000. SLA 92%. Asset 50 installed/5
   available/2 maintenance.

### SLA compliance
1. Query July, category=no_internet → 10 tickets, 9 within 4h SLA (90%),
   1 breach. Avg resolution 2.5h. Avg FRT 10min.

### Stock card
1. Query product KBL-UTP-CAT6, POP-JKT01, July → movement history
   (receive 100, issue 20 SPK-001, transfer 30 POP-BDG01, adjust -5)
   with balance_after each row.

### Audit log
1. Query user admin, module SPK, July → activity_log entries (create,
   assign, approve, cancel) with before/after properties.

### Authorization
1. Technician view own report → 200. View other → 403.
2. NOC view asset + SLA report → 200. View business → 403.
3. Manager view all + export → 200.

## Acceptance Criteria

- [ ] 7 report types (technician/unit/business/asset/sla/stock-card/
  audit-log) dengan Query classes real-time.
- [ ] NO static performance/metrics tables (hitung dari event history).
- [ ] Technician performance: SPK count + ticket count + avg completion +
  SLA + rating + FRT + workload + breakdown.
- [ ] Unit performance: produktivitas + workload + member ranking.
- [ ] Business metrics: MRR + active/churn/new + revenue + outstanding +
  suspend + SLA + asset utilization.
- [ ] Date range filter + real-time (re-run different period = different
  result).
- [ ] Export Excel (tabular) + PDF (single summary).
- [ ] Policy per report type (technician self-limit, NOC scope, manager
  full).
- [ ] DB index optimasi untuk query berat (work_orders.assigned_to +
  completed_at, tickets.assigned_to + resolved_at, etc).
- [ ] Feature test ≥ 80% coverage (assert metrics computation correct).
- [ ] UI: report list page (card per type), report detail (filter form +
  result table/chart + export button), technician self-report dashboard
  widget.
- [ ] UI pakai Components/ui + composite.
- [ ] Dark mode + responsive.

## Module Dependencies

- **Depends on (Phase 1-7):** SEMUA module (read-only Query lintas semua
  tabel). NO direct Service import — Query classes akses Model langsung
  (exception ke dependency rule: Reporting = read-only cross-cutting,
  boleh query semua model termasuk module-owned). Documented exception.
- **Consumed by:** none (terminal, read-only).
- **Shared Core:** EmployeeEvaluation (app/Models/Core/) — reporting baca
  evaluation untuk rating/score metrics. Lihat `docs/modules/performance.md`.
- **Performance vs Reporting (D1):** performance.md = EmployeeEvaluation
  WRITE entity (score/rating/FRT per SPK/Ticket, cross-cutting). reporting.md
  = READ-ONLY laporan (hitung dari history + evaluation). Distinct:
  evaluation=write, reporting=read.
