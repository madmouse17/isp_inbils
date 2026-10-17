# QA/REVIEW: gates, review, docs, release (both repos)

Status: done
Role: qa/reviewer
Blocked by: 01–05
Spec: `../spec.md`

## Scope

Full PHPUnit, build + full E2E, mobile tests + journeys, security review (new endpoints: ownership, allow-list, no technician phone), user guides + screenshots (F9), CHANGELOG (`migrate --force`), Docker refresh, commit + push both repos.

## Acceptance criteria

- [x] All gates green with evidence; review SIGNED; both repos pushed.

## Comments

### 2026-10-16 FieldTripDeviceQa: device journey + user guides (not the gates)

Scope done: AVD `inbils_pixel7` (Pixel 7/API 35), technician and customer dev debug APKs built with a gitignored `env/dev.local.json` (`http://127.0.0.1:8010`) + `adb reverse tcp:8010 tcp:80` against the Sail dev DB; user guides `teknisi.md`, `pelanggan.md`, `web.md`; no mobile or web source touched. Not part of this slice (still open for the ticket): full PHPUnit, build + E2E, mobile `flutter test`/integration journeys (`journeys_test`, `accessibility_test`, `text_scale_test`, `docs_screenshots_test`), security review, CHANGELOG, Docker refresh, commit, push.

**Setup done on the dev DB** (needed, not a defect): the dev DB had the trip migration pending; ran only `sail artisan migrate --path=Modules/SPK/database/migrations/2026_10_20_100000_add_trip_timestamps_to_work_orders_table.php` (the three `platform_*` migrations of the other lane stay pending). Frontend rebuilt with `npx vite build` (5.4 s; `tsc` skipped because other lanes had files in flight), since `public/build` predated `WorkOrderTimeline.tsx`.

**Data created on the dev DB (QA leftovers, keep or delete):** customer `CUS-00001` Hendri Kusnadi (user 64, demo password set, installation address Jl. Cimbuleuit No. 18, Bandung, -6.8741/107.6046, subscription `SUB-2026-00041`, POP Dago); `SPK-2026-00049` (installation, id 61; walked assigned → en route → started → 1 evidence photo → submitted, **rejected by `manager@demo.inbils.test`**, re-assigned, en route again, now `assigned`); `SPK-2026-00050` (maintenance, id 62, scheduled 19 Okt 2026; assigned → en route → started, now `in_progress`); 2 `technician_en_route` notifications for Customer #87.

#### Journey exercised on the device

| Step | Observed |
|---|---|
| Technician: SPK detail at `assigned` | Stepper "Progres kunjungan" 4 steps, step 1 current with time; Pelanggan card (name, `SUB… · paket`, phone as text, Telepon, WhatsApp, Alamat pemasangan + Buka di Maps); card "POP / gudang" with its own Buka di Maps; bar = filled "Saya berangkat" + outlined "Mulai" |
| Links (intent captured with `dumpsys activity`) | Installation Maps → `https://www.google.com/maps/dir/?api=1&destination=-6.8741,107.6046` (the home, F4 fixed); POP Maps → `destination=-6.886,107.6125`; Telepon → `tel:` dialer; WhatsApp → `https://wa.me/6281322004455` (Chrome, no WhatsApp on the AVD) |
| Saya berangkat | Confirm "Berangkat ke lokasi?" → location permission (Precise, while using) → step 2 checked with time, bar = only "Mulai". DB: `en_route_at` set, status still `assigned`, activity `en_route` with `distance_m: 74` (fix 74 m from the **home**, not the POP 1.6 km away) |
| Mulai | Confirm "Mulai SPK ini?" → step 3 current, status Dikerjakan, bar = disabled "Selesai dipasang · kirim untuk review" + red "Belum ada foto bukti" |
| Photo + Selesai dipasang | Gallery picker → caption → uploaded; button enabled; one tap submits (no confirm dialog, as the guide already said) → step 4 checked, chip Menunggu review; `submitted_at` set |
| Customer (Hendri): Beranda | "Sedang berjalan" card "SPK-2026-00050 / Perawatan / Ditugaskan / Jadwal 19 Okt 2026"; after the technician left: bell badge 2 on resume, card still "Ditugaskan" until pull-to-refresh, then "Dalam perjalanan" |
| Customer: notification | "Teknisi dalam perjalanan / Teknisi Demo sedang menuju lokasi Anda." (2 unread) → tap newest opens visit 62, notification marked read (`read_at` set), back returns to the list |
| Customer: Akun → Kunjungan teknisi | List: 00050 Perawatan Dalam perjalanan (Jadwal 19 Okt) above 00049 Instalasi Selesai dipasang; detail: chip, Tahapan with times, Rincian (Jadwal / Teknisi "Demo" first name only / Alamat pemasangan). Technician started → pull-to-refresh → "Sedang dikerjakan" step 3 |
| Dark mode (`cmd uimode night yes`) | Detail legible; sampled text `#BFDBFE` on chip `#1E3A8A` = 7.29:1, label `#C3C6D7` on `#191B23` = 10.13:1 (`check_contrast`) |
| Reject + re-assign (web, as manager; the creator is refused with toast "Data gagal disimpan. Periksa input.") | `rejected`: web timeline shows badge "Perlu perbaikan" on step 3, step 4 "Belum dicapai", row "Alasan penolakan"; technician list no longer holds the SPK. After "Tugaskan" again: status `assigned`, `en_route_at` and `submitted_at` null, technician stepper starts over at "Ditugaskan 23:21" and shows the "Alasan dikembalikan" box; customer API `state: open, current: assigned`, all later steps null |
| Web SPK detail (browser, Sail) | Card "Tahap kunjungan" under Tindakan renders for draft (4× "Belum dicapai"), waiting review (4 checked, times 22:58 / 23:06 / 23:07 / 23:08, buttons Setujui/Tolak/Batalkan) and rejected; "Check-in: 74 m dari lokasi (akurasi ±5 m)" |
| Web Penugasan (browser) | SPK-2026-00049, Petugas saat ini "Demo Technician" with badge "Dalam perjalanan" under the name, button "Ganti petugas" (filter Sudah ditugaskan) |
| API contract spot checks (curl, demo tokens) | Technician show carries `steps`/`current`/`actions.en_route`/`site`/`pop`/`customer.phone_e164`; customer show keys exactly `id, code, type, scheduled_date, state, steps, current, technician, installation_address`; 0 matches of the technician phone/email in the customer body |

#### Findings

| # | Where | Finding | Severity | Fixed? |
|---|---|---|---|---|
| 1 | Technician app, SPK after rework | Hero shows "Dimulai 16 Okt 2026 23:07" (old `started_at`) above a stepper that starts over at "Ditugaskan". `SpkService::assign` clears `en_route_at` and `submitted_at` but not `started_at` (DB after re-assign: `started_at` still set; API `started_at` and `rejection_reason` still returned) | Medium | No. needs-change: `Modules/SPK/app/Services/SpkService.php` (clear `started_at` on a rework/technician change, or stop exposing it for `assigned`) + a test in `WorkOrderEnRouteResetTest` |
| 2 | Technician app SPK hero | Shows the raw type key ("installation · Prioritas sedang", "maintenance · …"), not the Indonesian label; the customer app and web use "Instalasi/Perawatan". Present before this lane (old `spk-detail-teal-light.png`) | Low | No |
| 3 | Technician app | Submit label "Selesai dipasang · kirim untuk review" is also used for a maintenance SPK (F3 decision, but reads oddly for "Ganti konektor kabel drop") | Low | No, owner decision |
| 4 | Technician app | A rejected SPK leaves the list until re-assigned, so the "Perlu perbaikan" chip on the stepper can only show on a detail screen that was already open (or from cache). Not reachable by tapping through; covered by widget tests only | Info | No |
| 5 | Customer Beranda | Bell badge refreshes on resume but the visit card stays "Ditugaskan" until pull-to-refresh (documented design in ticket 05); a customer who opens the app from the badge sees a stale step. Guide now says so | Low | No |
| 6 | Customer notifications | Two en-route notifications of two visits have identical text (no SPK code or type), so they cannot be told apart in the list | Low | No |
| 7 | Web SPK detail | "Lokasi" still shows the POP while "Check-in 74 m dari lokasi" measures to the customer's home. Guide now explains both | Low | No (web `Show.tsx` not in this slice) |
| 8 | Web reject by the SPK creator | Toast "Data gagal disimpan. Periksa input." without the server reason inline (existing maker-checker behaviour) | Info | No |
| 9 | Dev environment | No queue worker in the Sail dev stack: technician-side `SPK ditugaskan`/rejection notifications and WhatsApp copies stay in `jobs` (6 pending); only the synchronous `technician_en_route` database notification arrives | Info | n/a |

No mobile production defect found that is fixable in the apps alone, so no mobile source or test changed (`git status` of `inbils-mobile` clean after cleanup).

#### Screenshots (real device, 1080×2400, dev flavour, blue brand `#2563EB`)

`docs/user-guide/images/technician/redesign/`: `spk-step-1-ditugaskan.png`, `spk-step-1-pelanggan-pop.png`, `spk-step-2-berangkat-konfirmasi.png`, `spk-step-2-dalam-perjalanan.png`, `spk-step-3-dikerjakan.png`, `spk-step-3-siap-dikirim.png`, `spk-step-4-selesai-dipasang.png`.

`docs/user-guide/images/customer/redesign/`: `kunjungan-beranda-ditugaskan.png`, `kunjungan-beranda-dalam-perjalanan.png`, `kunjungan-notifikasi.png`, `kunjungan-akun-menu.png`, `kunjungan-daftar.png`, `kunjungan-detail-dalam-perjalanan.png`, `kunjungan-detail-dikerjakan.png`, `kunjungan-detail-selesai.png`.

Web browser shots (SPK detail draft / waiting review / rejected, Penugasan badge) were taken for review only, not saved to `docs/`: `images/web/spk-show.png` stays as is until `npm run docs:screenshots` (it opens the first SPK row, so the "Tahap kunjungan" card appears by itself).

#### Docs changed

- `docs/user-guide/teknisi.md` §3: flow sentence, "Detail SPK dan progres kunjungan" (stepper, Pelanggan / Alamat pemasangan / POP cards, Telepon / WhatsApp / Buka di Maps), "Saya berangkat", "Mulai", "Kirim untuk review" (new button label, step 4, rework return), error list and FAQ offline line.
- `docs/user-guide/pelanggan.md`: Beranda card (+ refresh behaviour), new "Kunjungan teknisi" section in bab 5 (Akun row, list, detail, states), notification step 2 + table row "Teknisi dalam perjalanan" (+ image), Akun menu row.
- `docs/user-guide/web.md`: §3.1 Penugasan column + badge note, §3.3 intro, Ditugaskan status row, Rincian (Lokasi vs Check-in), card line and new "Tahap kunjungan" section, "Memulai dan mengajukan SPK" note.
- Image check: every `![](…)` of `teknisi.md` (28) and `pelanggan.md` (26) exists; `web.md` has 3 missing images that belong to other lanes (`announcements.png`, `announcements-create.png`, `roles-show.png`), so `inbils:docs:pdf --only=web` fails until `npm run docs:screenshots` ran. PDFs were not re-rendered.

#### Left for Main

- `npm run docs:screenshots` (then check `spk-show.png`), `php artisan inbils:docs:pdf` for the three guides, then commit. Optional: an extra entry in `scripts/docs-screenshots.json` for an SPK with progress (needs-change there, file owned by another lane).
- Devices: AVD left running; both dev apps installed with data; `adb reverse` removed; animation scales back to 1.0; `env/dev.local.json` files deleted. The installed dev APKs point at `127.0.0.1:8010`, so they need `adb reverse tcp:8010 tcp:80` again to reach Sail.
- Findings 1 (backend) is the only one that contradicts a stated rule ("a reworked visit never shows a finish before its restart"): worth a fix + test before the release.

2026-10-17, orchestrator (combined gate for announcements-promo, field-trip-status, platform-superadmin, push-notifications 01, mobile-redesign 02/09): full PHPUnit 3781 passed (2 fixture tests updated for `device_tokens`); full chromium E2E 262 passed, 4 skipped, 0 failed (two consecutive runs; one product defect fixed: invalid `pattern` regex on the tenant code inputs); security reviews SIGNED (announcements, platform incl. impersonation re-pass fixes); `composer audit` clean after `symfony/yaml` update (Laravel 12.70.0); docs screenshots 87/87; PDFs re-rendered; real FCM V1 dry run accepted.
