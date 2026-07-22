# Plan Revisi Customer, SPK Instalasi, dan Delegasi Ticket

## 1. Tujuan

Menyederhanakan proses onboarding pelanggan menjadi satu form Customer yang sekaligus menyimpan identitas, kontak utama, alamat domisili, alamat instalasi, dokumen, dan lokasi rumah. Setelah Customer berhasil dibuat, sistem otomatis membuat satu SPK instalasi. SPK tampil pada halaman detail Customer dan dapat didelegasikan ke tim teknis serta teknisi. Ticket tetap dapat dibuat tanpa Customer sebagai antrean global dalam company, tetapi harus dapat didelegasikan ke tim teknis dan teknisi.

Plan ini tidak langsung menghapus tabel `customer_addresses` dan `customer_contacts`. `service_subscriptions.installation_address_id` masih bergantung pada `customer_addresses`. UI Manage Address dan Manage Contact Person dihapus; form Customer menjadi satu-satunya UI pengelolaan alamat canonical dan kontak utama.

## 2. Scope

### Termasuk

- Revisi schema dan kontrak data Customer.
- Form Create/Edit Customer terpadu.
- Switch "Alamat instalasi sama dengan alamat domisili".
- Upload foto KTP dan foto rumah.
- Penyimpanan share location rumah.
- Penghapusan halaman, link, dan route UI Manage Address/Contact Person.
- Pembuatan otomatis satu SPK installation saat Customer dibuat.
- Tab SPK pada Show Customer.
- Tampilan ringkas status SPK: `pending`, `on_progress`, `done`.
- Delegasi SPK ke tim teknis dan teknisi individual.
- Delegasi Ticket global ke tim teknis dan teknisi individual.
- Tenant isolation, authorization, activity log, migration safety, tests.

### Tidak termasuk

- Menghapus fisik tabel/model `customer_addresses`.
- Menghapus data alamat/kontak tambahan yang sudah ada.
- Mengganti seluruh state machine internal SPK.
- Membuat master data wilayah Indonesia baru.
- Auto-routing, optimasi jadwal, kapasitas tim, kalender teknisi, atau notifikasi real-time.
- Membuat konsep tim baru; gunakan `organization_units` bertipe `team`.

## 3. Keputusan Desain

### 3.1 Alamat dan kontak

- `customer_addresses` tetap menjadi penyimpanan alamat canonical.
- Alamat domisili adalah row dengan `is_primary=true`.
- Alamat instalasi adalah row dengan `is_installation_point=true`.
- Jika kedua alamat sama, gunakan satu row dengan kedua flag bernilai `true`. Jangan menduplikasi alamat.
- Jika berbeda, gunakan dua row terpisah.
- `customer_contacts` tetap dipertahankan untuk compatibility data lama. Form Customer hanya mengelola satu primary contact.
- Alamat/kontak tambahan lama tidak dihapus otomatis. Tampilkan sebagai data legacy read-only bila ditemukan.

### 3.2 Wilayah

- Gunakan `VARCHAR(100)` untuk provinsi, kota/kabupaten, kecamatan, kelurahan pada revisi ini.
- Jangan membuat foreign key wilayah tanpa master wilayah dan source data resmi.
- Upgrade ke FK dilakukan melalui migration terpisah saat master wilayah tersedia.

### 3.3 Status SPK

Status `pending`, `on_progress`, `done` adalah proyeksi ringkas untuk halaman Customer, bukan pengganti workflow internal SPK.

| Status Customer | Status internal SPK |
|---|---|
| `pending` | `draft`, `generated`, `assigned`, `rejected` |
| `on_progress` | `in_progress`, `waiting_review` |
| `done` | `completed` |
| `cancelled` | `cancelled`, ditampilkan terpisah |

- Nilai wire/API: `on_progress`.
- Label UI: `On Progress`.
- Status internal tidak boleh diubah langsung melalui mass assignment.
- Perubahan status wajib memakai service transition SPK.
- `done` tetap melalui submit bukti dan approval. Jangan melewati side effect stok, pemasangan aset, aktivasi subscription, dan invoice.

### 3.4 Tim teknis

- Tim teknis menggunakan `organization_units` dengan `type=team`, aktif, company sama, dan memiliki minimal satu employee aktif dengan role `technician`.
- `assigned_team_id` menyimpan pemilik antrean/dispatch.
- `assigned_to` tetap menyimpan teknisi individual sebagai executor.
- Ticket atau SPK boleh berada pada team queue tanpa executor saat pending.
- Pekerjaan hanya dapat dimulai setelah teknisi individual ditentukan.
- Jika team dipilih, teknisi wajib anggota team tersebut.

### 3.5 Ticket global

- Global berarti tidak wajib memiliki `customer_id`, `subscription_id`, asset, atau location.
- Global tetap dibatasi oleh `company_id`; bukan cross-company.
- Ticket global dapat didelegasikan ke team queue, lalu ke teknisi individual.

## 4. Kontrak Field Customer

### 4.1 Field profile pada `customers`

| Label Form | Field DB | Tipe | Validasi awal |
|---|---|---|---|
| Nama Lengkap | `nama_lengkap` | `VARCHAR(150)` | required, max 150 |
| Tipe Pembayaran | `tipe_pembayaran` | `VARCHAR(30)` | required, allowlist sesuai opsi bisnis |
| Tanggal Lahir | `tanggal_lahir` | `DATE` nullable | sebelum/atau hari ini |
| Nomor Identitas KTP | `nomor_ktp` | `VARCHAR(16)` nullable | tepat 16 digit bila diisi |
| Alamat Email | `email` | `VARCHAR(150)` nullable | email, max 150 |
| No HP | `nomor_hp` | `VARCHAR(20)` | required, format nomor telepon |
| No Telepon Rumah | `nomor_telepon_rumah` | `VARCHAR(20)` nullable | format nomor telepon |
| NPWP | `npwp` | `VARCHAR(25)` nullable | max 25 |
| Status | `status` | `VARCHAR(20)` | allowlist `active`, `inactive` |
| Alamat sama | `alamat_instalasi_sama` | `BOOLEAN` | default false |

Catatan migration:

- Project saat ini memakai `name`, `phone`, `tax_id`, dan `is_active`. Implementasi harus memilih rename additive/backfill yang aman, bukan drop langsung.
- Rekomendasi compatibility: rename atau migrasikan bertahap `name` ke `nama_lengkap`, `phone` ke `nomor_hp`, `tax_id` ke `npwp`, `is_active` ke proyeksi `status` atau pertahankan boolean sebagai source of truth.
- Jangan menyimpan status string dan boolean aktif secara bersamaan tanpa satu source of truth.
- `nomor_ktp` adalah PII. Jangan tampilkan penuh pada list, log, atau audit payload. Masking default: hanya empat digit terakhir terlihat.
- Unique KTP per company belum diterapkan sampai aturan bisnis pelanggan duplikat dikunci.

### 4.2 Field alamat pada `customer_addresses`

Tambahkan field berikut secara additive:

| Konteks Form | Field DB | Tipe |
|---|---|---|
| Alamat lengkap | `address` | `TEXT` |
| RT | `rt` | `VARCHAR(3)` nullable |
| RW | `rw` | `VARCHAR(3)` nullable |
| Kode Pos | `postal_code` | `VARCHAR(5)` nullable |
| Provinsi | `province` | `VARCHAR(100)` nullable |
| Kota/Kabupaten | `city` | `VARCHAR(100)` nullable |
| Kecamatan | `district` | `VARCHAR(100)` nullable |
| Kelurahan | `village` | `VARCHAR(100)` nullable |
| Latitude | `lat` | existing decimal nullable |
| Longitude | `lng` | existing decimal nullable |
| Share location | `share_location_url` | `VARCHAR(2048)` nullable |

Mapping request menggunakan nama eksplisit dari kebutuhan:

- `alamat_domisili`, `rt_domisili`, `rw_domisili`, `kode_pos_domisili`, `provinsi_domisili`, `kota_kabupaten_domisili`, `kecamatan_domisili`, `kelurahan_domisili`.
- `alamat_instalasi`, `rt_instalasi`, `rw_instalasi`, `kode_pos_instalasi`, `provinsi_instalasi`, `kota_kabupaten_instalasi`, `kecamatan_instalasi`, `kelurahan_instalasi`.
- Service memetakan kedua grup request ke row `customer_addresses`; jangan membuat semua kolom berulang pada `customers`.
- RT/RW harus string agar nol di depan tidak hilang.
- Kode pos harus string tepat lima digit bila diisi.
- `lat` harus `-90..90`; `lng` harus `-180..180`.
- `share_location_url`, bila dipakai, hanya menerima HTTPS. Koordinat tetap menjadi source of truth lokasi.

### 4.3 Media

- `foto_ktp`: MediaLibrary collection private `identity_document`, satu file.
- `foto_rumah`: MediaLibrary collection private `house_photo`, satu file.
- Format: JPEG, PNG, WebP.
- Ukuran maksimum awal: 5 MB per file.
- Filename dibuat ulang oleh server.
- Preview/download melalui controller terautentikasi dan policy Customer; jangan gunakan public static URL.
- Saat replace, file lama dihapus hanya setelah file baru berhasil disimpan.
- Jangan simpan binary/path sensitif dalam activity log.

## 5. Form Create/Edit Customer

### Section

1. Identitas: nama lengkap, tanggal lahir, KTP, email, HP, telepon rumah, NPWP, tipe pembayaran, status.
2. Alamat domisili: alamat lengkap, RT/RW, kode pos, wilayah, lokasi.
3. Switch `Alamat instalasi sama dengan alamat domisili`.
4. Alamat instalasi: hanya tampil jika switch tidak aktif.
5. Dokumen: foto KTP dan foto rumah.
6. Kontak utama: gunakan identitas Customer sebagai primary contact; field PIC terpisah hanya dipertahankan jika customer Company masih membutuhkan PIC.

### Perilaku switch

- Saat aktif, field instalasi disembunyikan dan error instalasi dibersihkan pada client.
- Server tetap menjadi source of truth; abaikan payload instalasi saat switch aktif.
- Service menyimpan satu address row dengan `is_primary=true` dan `is_installation_point=true`.
- Saat switch dimatikan, field instalasi wajib diisi dan service membuat/update row instalasi terpisah.
- Edit tidak boleh menghapus/recreate address yang sudah direferensikan subscription.

### UI

- Reuse `Input`, `Textarea`, `Switch`, `FileUpload`, `Card`, `SearchSelect`, dan composite existing.
- Extract satu form component yang dipakai Create dan Edit; jangan duplikasi seluruh form.
- Error nested ditampilkan pada field terkait.
- Mobile responsive, dark mode, label terasosiasi, fokus keyboard terlihat.
- Hapus tombol/link `Manage Addresses` dan `Manage Contacts`.

## 6. Write Flow Customer dan Auto-SPK

Gunakan cross-domain Action pada `app/Actions/`. Jangan menambahkan relation SPK pada `Customer` karena Core tidak boleh bergantung pada Module SPK.

Alur satu transaksi:

1. Authorize `customer.create`.
2. Validasi seluruh profile, alamat, media metadata, dan tenant reference.
3. Buat Customer.
4. Buat/update address canonical domisili dan instalasi.
5. Buat/update primary contact bila masih dipakai.
6. Buat akun User Customer sesuai keputusan account lifecycle existing.
7. Buat tepat satu WorkOrder `type=installation`, `source=customer_creation`, status internal `generated`.
8. Isi `customer_id`; subscription/location boleh null saat onboarding belum lengkap.
9. Simpan activity log Customer dan SPK.
10. Commit DB. Media disimpan dengan kompensasi cleanup karena storage tidak ikut rollback DB.

### Idempotency

- Tambah nullable `work_orders.origin_key`.
- Isi auto-SPK dengan `customer-installation:{customer_id}`.
- Tambah unique `(company_id, origin_key)`.
- Manual SPK tetap memiliki `origin_key=null`.
- Retry atau double-submit tidak boleh membuat SPK kedua.
- Jika SPK gagal dibuat, Customer dan User tidak boleh tersimpan sebagai orphan.

### Prerequisite sebelum start

Auto-SPK boleh pending tanpa subscription karena dibuat saat Customer onboarding. Sebelum assign/start, validasi:

- alamat instalasi tersedia;
- subscription installation milik Customer tersedia;
- team dan teknisi valid;
- seluruh reference berada pada company sama.

Jika bisnis mensyaratkan SPK dapat berjalan tanpa subscription, side effect completion subscription/billing harus dibuat conditional dan diuji eksplisit.

## 7. SPK pada Show Customer

### Data

- Query SPK melalui reader/query milik Module SPK.
- Kirim Inertia props terpisah: `customer`, `workOrders`, `technicalTeams`, `technicians`, `can`.
- Jangan memasukkan `WorkOrderResource` ke `CustomerResource`.
- Hanya kirim data SPK bila actor memiliki `spk.view`.

### Tampilan

Tambahkan tab `SPK` pada Show Customer dengan:

- kode SPK;
- tipe;
- status ringkas;
- status internal sebagai detail sekunder;
- team teknis;
- teknisi executor;
- jadwal;
- link ke Show SPK.

Kontrol cepat:

- Assign/reassign team dan teknisi, hanya untuk permission `spk.assign`.
- Start, hanya oleh teknisi executor atau actor berwenang.
- Submit review dan approval tetap melalui workflow SPK existing.
- Jangan membuat endpoint raw `update status`.

## 8. Schema Assignment Team

Migration additive:

- `work_orders.assigned_team_id` nullable FK ke `organization_units.id`, restrict/null sesuai kebijakan delete organisasi.
- `work_orders.origin_key` nullable `VARCHAR`, unique bersama `company_id`.
- `tickets.assigned_team_id` nullable FK ke `organization_units.id`.
- Index `(company_id, assigned_team_id, status)` pada WorkOrder dan Ticket.

Tidak perlu membuat tabel `technical_teams` baru. Team teknis adalah query atas organization unit aktif yang memiliki technician aktif.

Validation service:

- Team company sama, aktif, `type=team`.
- Technician user aktif, employee profile aktif, role technician, company sama.
- Technician adalah anggota team yang dipilih.
- Browser tidak boleh mengirim `company_id`.
- `Rule::exists` wajib difilter `company_id`; ulangi assertion pada Service.

Assignment history:

- Histori teknisi tetap memakai `work_order_assignments`.
- Perubahan team minimal dicatat ActivityLog.
- Tabel history team baru ditunda sampai reporting histori team benar-benar dibutuhkan.

## 9. Delegasi Ticket Global

### Backend

- Perluas `TicketService::assign()` menjadi delegasi team dan optional executor.
- Controller hanya meneruskan validated data ke service.
- Ganti validasi global `exists:users,id` dengan company-scoped validation.
- Gunakan object-level Policy untuk assign, start, resolve, close, dan spawn SPK.
- Team-only Ticket berstatus `assigned`, tetapi belum boleh start sebelum executor dipilih.
- Technician hanya boleh start/resolve Ticket yang ditugaskan kepadanya.
- Manager/admin dapat reassign team atau executor sesuai permission.
- Simpan actor assignment dan activity log.

### Frontend

- Ganti input numeric Handler ID dengan `SearchSelect` team dan teknisi.
- Pilih team dahulu; daftar teknisi difilter berdasarkan anggota team.
- Tampilkan `Global Company Ticket` bila Customer kosong.
- Index technician hanya menampilkan assignment miliknya atau team queue jika fitur claim disetujui kemudian.
- Tombol spawn SPK harus bergantung pada `spawned_spk_id`, bukan `resolution_note`.

### State machine Ticket

```text
open -> assigned -> on_progress -> resolved -> closed
```

- `open -> assigned`: team atau team + executor ditentukan.
- `assigned -> on_progress`: executor memulai pekerjaan.
- `on_progress -> resolved`: executor mengisi resolution note.
- `resolved -> closed`: manager/admin menutup Ticket.
- Jangan menyediakan update status bebas.

## 10. Authorization dan Security

- Semua query Customer, address, contact, WorkOrder, Ticket, team, employee, User wajib company-scoped.
- Cross-company child/team/user harus menghasilkan 404/403/validation error tanpa membocorkan keberadaan data.
- Permission form Customer mengikuti `customer.create`/`customer.update`; permission `customer.address.manage` didepresiasi setelah route lama dihapus.
- Tab SPK mengikuti `spk.view`; assignment mengikuti `spk.assign`.
- Ticket assignment mengikuti `ticket.assign`; start/resolve juga wajib ownership executor.
- Foto KTP/rumah dan share location diperlakukan sebagai data pribadi.
- KTP dimasking pada response umum dan activity log.
- Jangan memakai nomor HP sebagai password awal. Gunakan invitation/reset-password atau temporary password acak dengan force reset.
- Audit event minimal: customer create/update, media replace, auto-SPK create, SPK assignment/reassignment/transition, Ticket assignment/reassignment/transition.

## 11. Migration Data Existing

Migration harus non-destructive:

1. Tambah kolom baru nullable/default aman.
2. Backfill `nama_lengkap` dari `name`, `nomor_hp` dari `phone`, `npwp` dari `tax_id` bila kolom baru dipilih.
3. Pilih domisili canonical dari latest `is_primary=true`; fallback address pertama.
4. Pilih instalasi canonical dari address yang direferensikan subscription; fallback `is_installation_point=true`.
5. Jangan ubah ID address yang direferensikan subscription.
6. Jangan hapus alamat/kontak tambahan.
7. Buat report data ambigu sebelum constraint diperketat.
8. Auto-SPK hanya dibuat untuk Customer baru setelah deployment. Backfill SPK untuk Customer lama harus command/task terpisah, dry-run, idempotent, dan disetujui Owner.
9. Route lama boleh redirect sementara ke Edit Customer selama satu release; hapus setelah tidak ada consumer.

## 12. File Terdampak

### Customer/Core

- `app/Http/Controllers/Admin/CustomerController.php`
- `app/Http/Requests/Admin/StoreCustomerRequest.php`
- `app/Http/Requests/Admin/UpdateCustomerRequest.php`
- `app/Http/Resources/CustomerResource.php`
- `app/Models/Core/Customer.php`
- `app/Models/Core/CustomerAddress.php`
- `app/Models/Core/CustomerContact.php`
- `app/Services/Core/CustomerService.php`
- `app/Actions/` untuk orchestration create Customer + SPK
- `routes/admin.php`
- migration additive Customer/address

### UI Customer

- `resources/js/Pages/Admin/Customers/Create.tsx`
- `resources/js/Pages/Admin/Customers/Edit.tsx`
- `resources/js/Pages/Admin/Customers/Show.tsx`
- `resources/js/Components/composite/CustomerRelatedTables.tsx`
- `resources/js/Pages/Admin/CustomerAddresses/Index.tsx` dihapus setelah route dihentikan
- `resources/js/Pages/Admin/CustomerContacts/Index.tsx` dihapus setelah route dihentikan
- `resources/js/types/models.d.ts`

### SPK

- `Modules/SPK/app/Models/WorkOrder.php`
- `Modules/SPK/app/Models/WorkOrderAssignment.php`
- `Modules/SPK/app/Services/SpkService.php`
- `Modules/SPK/app/Http/Controllers/WorkOrderController.php`
- `Modules/SPK/app/Http/Resources/WorkOrderResource.php`
- `Modules/SPK/app/Policies/WorkOrderPolicy.php`
- migration additive `assigned_team_id` dan `origin_key`

### Ticketing

- `Modules/Ticketing/app/Models/Ticket.php`
- `Modules/Ticketing/app/Services/TicketService.php`
- `Modules/Ticketing/app/Http/Controllers/TicketController.php`
- `Modules/Ticketing/app/Http/Resources/TicketResource.php`
- `Modules/Ticketing/app/Policies/TicketPolicy.php`
- `Modules/Ticketing/routes/web.php`
- migration additive `assigned_team_id`
- `resources/js/Pages/Admin/Tickets/Index.tsx`
- `resources/js/Pages/Admin/Tickets/Show.tsx`
- `resources/js/types/ticketing.d.ts`

### Cleanup address/contact

Setelah form terpadu dan regression test lulus:

- Hapus route manage address/contact.
- Hapus controller dan FormRequest khusus address/contact bila tidak digunakan.
- Pertahankan model/resource/factory address selama subscription masih bergantung padanya.

## 13. Urutan Implementasi

1. Bekukan opsi `tipe_pembayaran`, aturan required field, account lifecycle, dan semantics share location.
2. Buat migration additive Customer/address/team assignment/origin key.
3. Tambah service validation team/technician dan policy object-level.
4. Implementasikan Action atomik Customer + address/contact + User + auto-SPK.
5. Implementasikan update Customer terpadu tanpa merusak subscription address reference.
6. Implementasikan media private dan authorized preview/download.
7. Buat reusable Customer form; pasang pada Create/Edit.
8. Tambah tab SPK dan kontrol lifecycle pada Show Customer.
9. Implementasikan delegasi team/executor pada SPK.
10. Implementasikan delegasi team/executor pada Ticket global.
11. Hapus UI/link Manage Address dan Manage Contact Person.
12. Setelah compatibility check, hapus/redirect route lama.
13. Update source-of-truth `docs/business/customer.md` dan `docs/business/ticketing.md` pada task implementasi.
14. Jalankan focused tests, full test relevan, static analysis, build, browser smoke test.

## 14. Test Plan

### Customer

- Create menyimpan seluruh field baru.
- Switch aktif menghasilkan satu address dengan dua flag.
- Switch nonaktif menghasilkan dua address canonical.
- Update tidak mengganti ID address yang dipakai subscription.
- Data alamat/kontak tambahan lama tidak hilang.
- Upload format/size invalid ditolak.
- Foto private tidak dapat diakses tanpa auth, permission, dan tenant tepat.
- Cross-company nested ID ditolak.

### Auto-SPK

- Customer create menghasilkan tepat satu SPK installation status ringkas pending.
- Kegagalan SPK menggagalkan Customer/User/address.
- Retry/double-submit tidak membuat duplicate SPK.
- Customer Show hanya menampilkan SPK Customer pada company aktif.
- Actor tanpa `spk.view` tidak menerima prop SPK.
- Mapping status ringkas benar.
- `done` tidak dapat melewati evidence/review/approval.

### Assignment

- Team harus aktif, type team, company sama, memiliki technician.
- Executor harus technician aktif dan anggota team.
- Reassignment menutup histori assignment aktif sebelumnya.
- Technician lain tidak dapat start/submit SPK.
- Activity log merekam actor, team lama/baru, executor lama/baru.

### Ticket

- Ticket tanpa Customer valid dan tetap company-scoped.
- Ticket dapat didelegasikan ke team lalu executor.
- Team/user cross-company ditolak.
- Team-only Ticket belum dapat start.
- Technician hanya melihat/menjalankan Ticket yang ditugaskan kepadanya.
- Spawn SPK concurrent tidak menghasilkan dua SPK.
- Lifecycle open sampai closed tetap lulus.

### Regression

- Subscription create tetap memilih installation address valid.
- SPK completion tetap menjalankan stock, asset, subscription, billing side effects.
- Selector Customer pada SPK, Ticket, Billing, NetworkAsset tetap kompatibel.
- Seeder production tidak membuat company/customer/SPK/Ticket.
- `php artisan test --compact` lulus.
- `composer analyse` lulus.
- `npm run build` lulus.

## 15. Acceptance Criteria

- [ ] Manage Address dan Manage Contact Person tidak lagi muncul sebagai halaman terpisah.
- [ ] Create/Edit Customer mengelola profile, domisili, instalasi, media, dan lokasi dalam satu form.
- [ ] Switch alamat sama bekerja di client dan ditegakkan kembali di server.
- [ ] Existing subscription address reference dan data legacy tidak hilang.
- [ ] Customer baru otomatis memiliki tepat satu SPK installation secara atomik dan idempotent.
- [ ] Show Customer memiliki tab SPK.
- [ ] Tab Customer menampilkan status ringkas pending/on progress/done tanpa merusak state machine internal.
- [ ] SPK dapat didelegasikan ke team teknis dan teknisi valid.
- [ ] Ticket global company dapat didelegasikan ke team teknis dan teknisi valid.
- [ ] Tidak ada direct Core Model ke SPK Model dependency.
- [ ] Semua state transition melalui Service/Action, bukan raw update.
- [ ] Semua reference tenant-safe dan action dilindungi object-level Policy.
- [ ] Foto KTP/rumah private; KTP dimasking; share location tidak diekspos tanpa izin.
- [ ] Activity log mencakup create, assignment, reassignment, dan transition.
- [ ] Focused tests, regression tests, static analysis, build, dark mode, responsive, dan accessibility lulus.

## 16. Risiko dan Gate Keputusan

Implementasi berhenti bila keputusan berikut belum tersedia:

- Opsi resmi `tipe_pembayaran`.
- Field wajib berdasarkan customer Individual/Company.
- Apakah Customer Company tetap membutuhkan PIC berbeda dari identitas Customer.
- Apakah auto-SPK boleh start sebelum subscription dibuat.
- Apakah anggota team boleh self-claim Ticket atau assignment individual hanya oleh manager/admin.
- Retention policy foto KTP/rumah saat Customer soft-delete.
- Apakah share location berupa koordinat, URL Google Maps, atau keduanya.

Default aman plan: koordinat menjadi source of truth; URL hanya metadata HTTPS; team-only belum boleh start; completion SPK tetap membutuhkan bukti dan approval; data lama tidak dihapus.
