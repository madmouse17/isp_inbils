# Draf kontrak pengganti DataTable P1 C, D, E

Tanggal: 2026-10-04. Status: **usulan untuk keputusan pengguna, bukan otorisasi implementasi**.

## Keputusan pengguna diperlukan

Pengguna dapat menyetujui, menolak, atau meminta revisi setiap lane secara terpisah:

| Lane | Roster yang diusulkan | Keputusan saat ini |
|---|---|---|
| C | Products, Categories, Units, Stocks, Stock Movements, Inventory Requests beserta tab Returns | Belum disetujui |
| D | Tickets, SPK/Work Orders, Network Assets | Belum disetujui |
| E | Receivables; kandidat Invoice Show dengan tabel Items dan Payments, menunggu penegasan arti invoice history | Belum disetujui; batas embedded history masih terbuka |

Alternatif untuk setiap lane: pulihkan acceptance asli dari sumber lain yang belum ditemukan, lalu nilai kontrak asli tersebut sebelum implementasi. Assessment tidak menemukan acceptance asli bagi seluruh 15 kartu; judul, snapshot recovery, status scheduled, dan implementasi yang sudah ada bukan pengganti persetujuan pengguna (`docs/plans/INBILS_OMP_BACKLOG.md:179-186`).

Dokumen ini hanya menawarkan kontrak baru. Tidak ada kartu yang ditandai selesai, tidak ada perubahan ledger atau status kartu, dan tidak ada klaim bahwa acceptance asli sudah pulih. Disposisi assessment tetap `needs_recovery`. Persetujuan satu lane tidak mengotorisasi lane lain. Draf ini tidak mengotorisasi kode, test, DB, Docker, deployment, atau mutasi Hermes.

## Dasar bukti dan batas pembacaan

- Template kontrak: bagian Approved replacement contract A+B, `docs/plans/INBILS_OMP_BACKLOG.md:153-155`. Bukti penutupan A+B tidak dihitung sebagai PASS baru C/D/E.
- Roster kandidat dan pengecualian: `docs/plans/INBILS_OMP_BACKLOG.md:182-186`.
- Raw-SQL audit: `docs/plans/INBILS_OMP_BACKLOG.md:188-193`.
- Semua nomor baris adalah rujukan snapshot saat penyusunan. Baca ulang source saat lane mendapat izin; perubahan source tidak boleh dianggap sebagai acceptance baru.

Istilah static raw tables pada assessment C perlu dibaca tepat: Products, Categories, dan Units memang memakai markup `<Table>`, tetapi memiliki pagination manual. Bukan berarti ketiganya tidak memiliki backend pagination. Source saat penyusunan memperlihatkan Products `<Table>` di `resources/js/Pages/Admin/Inventory/Products/Index.tsx:114` dan `<Pagination>` di `:168`; Categories di `resources/js/Pages/Admin/Inventory/Categories/Index.tsx:128,179`; Units di `resources/js/Pages/Admin/Inventory/Units/Index.tsx:88,124`. Usulan tidak menganggapnya canonical hanya karena memakai `DataTableActions`, dan tidak mengusulkan rewrite halaman yang sudah memenuhi kontrak.

## Acceptance bersama yang diusulkan

Ketentuan ini berlaku untuk setiap baris roster lane yang disetujui, termasuk masing-masing tab/tabel yang secara eksplisit dipilih. Ini target kontrak pengganti, bukan pernyataan bahwa semuanya sudah ada atau sudah gagal.

1. Pertahankan perilaku search/filter yang sudah ada, nilai filter, kolom, link, aksi, dan arti data. Tidak menambah filter bisnis baru. Catat perilaku yang tidak tersedia sebagai tidak berlaku, bukan hasil PASS atau alasan menambah fitur.
2. Sort diproses server melalui allowlist backend; UI hanya menawarkan key yang didukung. Buktikan arah naik/turun dan penanganan key tidak valid tanpa mengubah default order yang sah.
3. Pagination server mempertahankan search/filter/sort dan ukuran halaman pada navigasi, reload, serta URL. Pakai helper/paginator canonical yang sudah tersedia, bukan kontrak kedua. Untuk tabel embedded yang dipilih, parameter antartabel tidak boleh saling menimpa.
4. Tenant scope dan RBAC berlaku di server, termasuk akses langsung URL dan query yang dimanipulasi. Visibilitas tombol bukan pengganti authorization. Tidak mengubah permission model atau workflow domain.
5. Parity index/export hanya untuk endpoint export yang sudah ada: filter, urutan, tenant scope, authorization, dan nilai kolom konsisten. Export bukan hanya baris halaman aktif. Tidak membuat route, format, atau fitur export baru.
6. Search/filter, sort, pagination, tab, dan aksi yang tersedia dapat dioperasikan dengan keyboard, mempunyai label/fokus yang jelas, dan tetap terbaca serta dapat digunakan pada 320px dan desktop dalam light/dark. Pertahankan penanganan loading, empty, dan error yang ada; tidak membuat redesign atau library baru.
7. Reuse implementasi yang ada. Perubahan hanya untuk gap yang dibuktikan terhadap kontrak yang disetujui. Presence sebuah komponen atau test bukan bukti bahwa acceptance terpenuhi.

## Lane C: Inventory operational tables

Roster tepat: enam halaman daftar dengan Returns sebagai tab/surface pendamping Inventory Requests. Setiap baris berikut menerima acceptance bersama. Roster berasal dari `docs/plans/INBILS_OMP_BACKLOG.md:182,186`.

| Halaman/surface | Bukti keadaan dan gap kandidat | Batas acceptance export |
|---|---|---|
| Products | Backend canonical `HasIndexQuery` + `ExportQuery` menurut ledger `:182`; UI masih `<Table>` dan pagination manual (`resources/js/Pages/Admin/Inventory/Products/Index.tsx:114,168`). Usulan: sambungkan kontrak canonical, pertahankan search/category/active filter yang tampak pada source `:36-47`. | Endpoint ada: `Modules/Inventory/routes/web.php:34`; parity wajib. |
| Categories | Backend canonical menurut ledger `:182`; UI `<Table>` dan pagination manual (`resources/js/Pages/Admin/Inventory/Categories/Index.tsx:128,179`). Usulan: cutover terbatas ke kontrak canonical tanpa mengubah CRUD/modal. | Endpoint ada: `Modules/Inventory/routes/web.php:37`; parity wajib. |
| Units | Backend canonical menurut ledger `:182`; UI `<Table>` dan pagination manual (`resources/js/Pages/Admin/Inventory/Units/Index.tsx:88,124`). Usulan: cutover terbatas; tidak mengarang search/filter baru. | Endpoint ada: `Modules/Inventory/routes/web.php:39`; parity wajib. |
| Stocks | UI canonical menurut ledger `:182`; backend `paginate(10)` di `Modules/Inventory/app/Http/Controllers/StockController.php:35-37`, tanpa `HasIndexQuery` menurut ledger `:182`. Filter `low_stock` memakai `whereRaw` di controller `:236`. Usulan: honor kontrak pagination/sort dan ganti raw query dengan Eloquent tanpa mengubah hasil low-stock. | Endpoint ada: `Modules/Inventory/routes/web.php:33`; gate `inventory.export` di `StockController.php:53`; parity wajib. |
| Stock Movements | UI canonical menurut ledger `:182`; `paginate(10)` di `Modules/Inventory/app/Http/Controllers/StockController.php:88-90`. Usulan: honor kontrak pagination/sort tanpa mengubah arti mutasi stok. | Endpoint ada: `Modules/Inventory/routes/web.php:48`; gate `inventory.export` di `StockController.php:103`; parity wajib. |
| Inventory Requests | Sudah canonical menurut ledger `:182`; belum ada gap tambahan yang dinyatakan terbukti. Verifikasi kontrak dan pertahankan alur permintaan. | Tidak ada endpoint export dalam roster route request (`Modules/Inventory/routes/web.php:12-23`); parity export tidak berlaku. |
| Returns (tab/surface Inventory Requests) | Sudah canonical menurut ledger `:182`; roster tab eksplisit di ledger `:186`. Verifikasi kontrak tanpa mengubah deklarasi/penerimaan/penyelesaian return. | Tidak ada endpoint export dalam roster return (`Modules/Inventory/routes/web.php:25-31`); parity export tidak berlaku. |

Gate `inventory.export` untuk Products/Categories/Units dicatat di `docs/plans/INBILS_OMP_BACKLOG.md:182`. Ketiadaan export Requests/Returns bukan gap yang harus ditutup.

Suppliers dikecualikan karena belum diimplementasikan (`docs/plans/INBILS_OMP_BACKLOG.md:182,186`). Tidak menambah halaman Suppliers, fitur stok, atau perubahan transaksi inventory.

Urutan kartu hanya untuk identifikasi kontrak: `t_dcf232f5` → `t_23e46c02` → `t_ee9c7127` → `t_43e33f86` → `t_39d87109` (`docs/plans/INBILS_OMP_BACKLOG.md:179`). Setelah lane yang disetujui melewati tester → QA independen → reviewer final, berhenti di batas C; D/E memerlukan keputusan sendiri.

## Lane D: Tickets, SPK/Work Orders, Network Assets

Roster tepat dan keadaan canonical bersumber pada `docs/plans/INBILS_OMP_BACKLOG.md:183,186`. Setiap halaman menerima acceptance bersama dan parity endpoint export yang sudah ada; tidak memperluas dispatch/provisioning.

| Halaman | Bukti keadaan dan gap kandidat | Usulan acceptance khusus |
|---|---|---|
| Tickets | Halaman canonical, backend `SORTABLE` dan export ada; `whereRaw('1 = 0')` pada `Modules/Ticketing/app/Http/Controllers/TicketController.php:154` tercatat di ledger `:183,190`. | Ganti guard raw dengan Eloquent sambil mempertahankan hasil kosong/fail-closed untuk handler nonaktif; jangan membuka akses. Pertahankan parity export. |
| SPK/Work Orders | Halaman canonical; controller memakai `HasIndexQuery::applySort` tanpa konstanta `SORTABLE`, serta `paginate(10)` (`docs/plans/INBILS_OMP_BACKLOG.md:183`). Ini latent Error menurut assessment, bukan HTTP 500 C/D/E yang sudah direproduksi. | Buktikan failing-before lalu lengkapi allowlist dan page-size handling; pertahankan filter, urutan default, tenant/RBAC dan parity export yang sudah ada. Jangan mengubah state machine SPK. |
| Network Assets | Halaman canonical dengan `SORTABLE` dan export; belum ada gap tambahan yang terbukti (`docs/plans/INBILS_OMP_BACKLOG.md:183`). | Verifikasi seluruh acceptance bersama; tidak rewrite jika kontrak sudah terpenuhi. |

Kesamaan bug WorkOrder dengan Organization hanya konteks diagnostik: Organization failing-before milik A+B dicatat di `docs/plans/INBILS_OMP_BACKLOG.md:167,170`, bukan bukti runtime WorkOrder.

Urutan kartu: `t_69dd2c1a` → `t_40d41840` → `t_1b37b49b` → `t_0f7d528b` → `t_3b241cec` (`docs/plans/INBILS_OMP_BACKLOG.md:179`). Setelah tester → QA independen → reviewer final, berhenti di batas D. Dispatch/My Work tetap di luar scope karena fase terpisah (`docs/plans/INBILS_OMP_BACKLOG.md:186`).

## Lane E: Receivables dan kandidat invoice embedded history

**Arti invoice history harus diputuskan pengguna sebelum roster E menjadi kontrak implementasi.** Assessment mengusulkan Invoice Show Items/Payments, tetapi juga mencatat customer embedded invoice history yang berbeda (`docs/plans/INBILS_OMP_BACKLOG.md:184,186`). Nama lane sendiri tidak cukup untuk memilih salah satunya.

| Kandidat surface | Bukti keadaan/gap | Usulan batas acceptance |
|---|---|---|
| Receivables | Raw `<Table>` di `resources/js/Pages/Admin/Billing/Receivables.tsx:72`; ledger `:184` mencatat perhitungan `BillingService::receivables()` dengan Eloquent buckets dan tanpa export. | Acceptance bersama untuk tabel server; pertahankan definisi bucket, saldo, dan total agregat. Pagination tidak boleh mengubah total menjadi subtotal halaman. Tidak ada raw-SQL gap yang dinyatakan pada service ini. |
| Invoice Show: Items | Raw `<Table>` di `resources/js/Pages/Admin/Billing/Invoices/Show.tsx:302`; kandidat ledger `:184,186`. | Hanya masuk bila pengguna memilih Invoice Show secara eksplisit. Acceptance bersama untuk Items, tetap terikat invoice/tenant yang berhak diakses; tidak mengubah line items atau total invoice. |
| Invoice Show: Payments | Raw `<Table>` di `resources/js/Pages/Admin/Billing/Invoices/Show.tsx:345`; kandidat ledger `:184,186`. | Hanya masuk bila pengguna memilih Invoice Show secara eksplisit. Acceptance bersama untuk Payments tanpa mengubah pencatatan/reversal pembayaran. |

Tidak ada parity export untuk roster E ini. Route Billing membedakan export index invoice (`Modules/Billing/routes/web.php:7`), Receivables (`:8`), PDF invoice (`:12`), serta mutasi item/payment (`:15-19`); tidak menyediakan export tabel embedded. PDF invoice bukan otorisasi membuat export Items/Payments.

Invoice index dikecualikan karena termasuk A+B yang telah ditutup; customer embedded invoice history di `CustomerRelatedTables.tsx` tetap dikecualikan dari usulan ini sampai pengguna memilih sebaliknya (`docs/plans/INBILS_OMP_BACKLOG.md:184,186`). Memilih customer history memerlukan revisi roster dan acceptance, bukan tambahan diam-diam. Embedded tables lain tidak ikut masuk.

Migrasi embedded menjadi server table adalah pilihan kontrak baru, bukan acceptance historis yang pulih: dokumen perencanaan lama justru mengecualikan pemaksaan embedded show tables menjadi server DataTable (`docs/plans/2026-08-22-table-button-implementation-graph.md:14-17`). Jika pengguna mempertahankan embedded table lokal, revisi acceptance E sebelum implementasi; jangan menganggap pagination/sort server sudah dikecualikan lewat diamnya pengguna.

Urutan kartu: `t_ef68d35a` → `t_353135bf` → `t_333772ab` → `t_9bc28da4` → `t_9df467ae` (`docs/plans/INBILS_OMP_BACKLOG.md:179`). Setelah tester → QA independen → reviewer final, berhenti di batas E; tidak lanjut ke Reporting atau lane lain.

## Gate verifikasi setelah persetujuan

Ini rencana verifikasi mendatang; tidak ada test atau runtime yang dijalankan untuk dokumen ini.

1. Cocokkan roster yang benar-benar disetujui, endpoint export yang sudah ada, allowlist, filter, dan batas tenant/RBAC. Coverage lama hanya titik awal (`docs/plans/INBILS_OMP_BACKLOG.md:185`), bukan PASS baru.
2. Untuk setiap fix, simpan bukti failing-before pada jalur yang terdampak, lalu passing-after dengan skenario yang sama. Untuk raw-SQL policy, bukti sebelum mencakup query path yang melanggar; bukti sesudah mencakup hilangnya raw query dan perilaku low-stock/fail-closed yang tetap benar. Jangan membuat klaim kegagalan runtime hanya dari dugaan source.
3. Tester menjalankan backend dan browser hanya pada MySQL `inbils_test` secara serialized, termasuk antar-lane. Pastikan identitas DB sebelum run; tidak memakai DB utama atau nama DB lain.
4. Buktikan setiap surface: search/filter yang ada, sort dua arah dan invalid key, pagination/query preservation, tenant/RBAC positif-negatif, parity export yang berlaku, keyboard, 320px/desktop, serta light/dark. Catat batas verifikasi secara eksplisit.
5. QA independen menilai hasil dan regresi domain. Reviewer final menilai bukti terhadap kontrak lane yang disetujui. Tidak boleh melewati urutan tester → QA independen → reviewer final.
6. Berhenti pada batas lane. Setiap penutupan mendatang harus diatribusikan pada kontrak pengganti yang disetujui, tidak pada acceptance asli yang belum pulih. Dokumen ini sendiri tidak mengubah status kartu.

## Pertanyaan terbuka untuk keputusan pengguna

1. Lane mana yang disetujui, ditolak, atau harus mencari sumber acceptance asli lain: C, D, dan E? Persetujuan dapat terpisah; gabungan harus tetap menyebut roster dan batas setiap lane.
2. Untuk E, apakah invoice history berarti Invoice Show Items/Payments sebagaimana kandidat assessment, atau customer embedded invoice history? Apakah tabel embedded yang dipilih memang harus menerima sort/pagination server? Sampai diputuskan, usulan E belum mengotorisasi migrasi embedded.
3. Apakah aturan Eloquent-only berlaku di seluruh repository atau khusus jalur index/export? Kedua opsi tetap mencakup gap Stock low-stock dan Ticket guard (`docs/plans/INBILS_OMP_BACKLOG.md:190`). Guard di query Admin/Reporting berada di luar C/D/E (`:191`), sehingga keputusan global membutuhkan scope terpisah.
4. Bagaimana keputusan per kasus untuk agregasi Reporting dan `CompleteSpkAction` yang memakai `DB::raw`, `SUM`, atau `AVG(TIMESTAMPDIFF(...))`? Ledger mendokumentasikannya sebagai kasus yang menunggu keputusan (`docs/plans/INBILS_OMP_BACKLOG.md:192`), bukan pengecualian yang telah diizinkan. Draf ini tidak mengotorisasi raw SQL, konversi global, atau perubahan rumus laporan.

Sampai keputusan pengguna tercatat, seluruh bagian acceptance di atas tetap usulan dan tidak menjadi instruksi eksekusi.
