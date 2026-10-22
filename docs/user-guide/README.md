# Panduan pengguna Inbils

Sumber Markdown, gambar, dan PDF panduan. Empat panduan berbahasa Indonesia:

| Berkas sumber | PDF | Pembaca |
| --- | --- | --- |
| `web.md` | `pdf/web.pdf` (Panduan Web Inbils) | admin, manajer, staf |
| `platform.md` | `pdf/platform.pdf` (Panduan Panel Operator Inbils) | operator platform (superadmin) |
| `teknisi.md` | `pdf/teknisi.pdf` (Panduan Aplikasi Teknisi) | teknisi (aplikasi Android) |
| `pelanggan.md` | `pdf/pelanggan.pdf` (Panduan Aplikasi Pelanggan) | pelanggan (aplikasi Android) |

Gambar dipakai dari Markdown dengan path relatif ke berkas ini, contoh `![Dasbor](images/web/dashboard.png)`.
Hanya PNG/JPG lokal yang diterima; gambar yang hilang, di luar folder ini, atau dari URL membuat perintah PDF gagal.

```
docs/user-guide/
  web.md  platform.md  teknisi.md  pelanggan.md
  images/web/<slug>.png         # skrip Playwright
  images/technician/<slug>.png  # integration test aplikasi teknisi
  images/customer/<slug>.png    # integration test aplikasi pelanggan
  pdf/<nama>.pdf                # hasil render, ikut di-commit
```

## 1. Screenshot web

Layar yang diambil didaftar di `scripts/docs-screenshots.json` (`slug`, `url`, `auth: false` untuk layar tamu,
`auth: "platform"` untuk operator demo dengan 2FA, `auth: "platform-enrol"` untuk operator yang belum mendaftarkan 2FA,
`phone: true` untuk layar yang diambil di ponsel, serta `actions` opsional: `click`, `hover`, dan `waitFor` dengan selektor
Playwright, `fill` dengan selektor dan nilai). Nilai `{{platformPassword}}` pada aksi `fill` dibaca dari
`E2ePlatformSeeder`; jangan menyalin kredensialnya ke manifest. Viewport 1280×800, tema terang. Layar tenant login
sebagai admin demo. Layar platform berjalan pada sesi terpisah sebagai operator demo. Layar `phone` memakai ukuran
`phone` di berkas yang sama (390×844), konteks sentuh dan piksel 2×. Setiap aksi dibatasi 30 detik, jadi selektor yang
tidak lagi cocok masuk daftar gagal alih-alih menahan seluruh proses. Penunjuk mouse diparkir di pojok sebelum gambar
diambil, kecuali layar itu memakai `hover`, supaya tooltip port tidak ikut tergambar. Datanya dari
`tests/e2e/setup.php`: `migrate:fresh --seed` (peran, izin, pengaturan sistem), `DemoUserSeeder`, `E2eFixtureSeeder`,
lalu `E2ePlatformSeeder`; seeder `database/seeders/Demo/*` tidak ikut, jadi layar yang butuh data harus memakai baris
dari seeder itu.

```bash
npm run build
npm run docs:screenshots   # reset data demo inbils_test, lalu mengambil docs/user-guide/images/web/<slug>.png
```

Skrip memakai server kanonik port 8010 dan mutex yang sama dengan `npm run test:e2e`; jangan jalankan bersamaan.
Layar yang gagal tampil didaftar di akhir dan layar lain tetap diambil.
Layar baru: tambah satu entri ke JSON. Selektor `click` dan `waitFor` harus cocok dengan satu elemen saja (Playwright
menolak lebih dari satu), jadi tambahkan `>> visible=true >> nth=0` bila teksnya muncul di beberapa tempat. Area `main`
menggulir sendiri, sehingga `fullPage` tidak membuat gambar lebih tinggi dari 800 piksel. Untuk memperlihatkan kartu di
bawah layar pertama, tambahkan `click` pada judul kartu itu (judul tidak melakukan apa pun, halaman hanya menggulir).

## 2. Screenshot aplikasi

Repo aplikasi ada di checkout terpisah `../inbils-mobile` (`technician/`, `customer/`). Tes memakai server API
palsu (`integration_test/screens.dart`, `fixtures.dart`) dan mengambil satu gambar per layar di daftar itu.
Nama berkas = judul layar yang di-slug (`Detail SPK (Mulai)` → `detail-spk-mulai.png`). Layar baru di `screens.dart`
otomatis ikut.

```bash
flutter emulators --launch inbils_pixel7      # tunggu sampai emulator-5554 muncul di `flutter devices`
# google-services.json ikut di repo aplikasi (config klien Firebase, bukan rahasia): tidak ada langkah salin
cd ../inbils-mobile/technician
flutter drive --flavor dev --dart-define-from-file=env/dev.json \
  --driver=test_driver/integration_test.dart \
  --target=integration_test/docs_screenshots_test.dart -d emulator-5554 \
  --dart-define=DOCS_SCREENSHOTS=true
cd ../customer
flutter drive --flavor dev --dart-define-from-file=env/dev.json \
  --driver=test_driver/integration_test.dart \
  --target=integration_test/docs_screenshots_test.dart -d emulator-5554 \
  --dart-define=DOCS_SCREENSHOTS=true
```

Tanpa `--flavor dev` Gradle membangun ketiga flavor dan `flutter drive` tidak menemukan APK-nya. Layar **Splash**
diambil sebagai bingkai beku di tengah animasi; layar lain tidak menampilkan splash.

Hasil ditulis driver ke `../../inbils/docs/user-guide/images/<technician|customer>/` (relatif terhadap folder
aplikasi). Ganti dengan `DOCS_IMAGES_DIR=/path/lain`. Memakai `flutter drive`, bukan `flutter test`, karena hanya
driver yang menerima gambar dari perangkat.

## 3. PDF

```bash
php -d memory_limit=1G artisan inbils:docs:pdf               # keempatnya
php -d memory_limit=1G artisan inbils:docs:pdf --only=platform # web | platform | teknisi | pelanggan
```

Dengan Sail: `./vendor/bin/sail exec -T -u sail laravel.test php -d memory_limit=1G artisan inbils:docs:pdf`.
Perintah membaca `docs/user-guide/<nama>.md` (CommonMark + tabel; HTML mentah dibuang), membuat sampul, daftar isi dari
judul H1/H2, nomor halaman di footer, dan menyisipkan gambar langsung ke PDF (dompdf, A4 potret, tanpa akses remote).
Berkas hilang atau gambar rusak → perintah keluar dengan kode gagal, PDF lain tetap dibuat. `--dir=` mengganti folder
sumber (dipakai tes).

Urutan rilis: ambil screenshot web, ambil screenshot aplikasi, perbarui Markdown bila UI berubah, render PDF, commit.
