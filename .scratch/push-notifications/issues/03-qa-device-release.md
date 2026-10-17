# QA: device smoke with real FCM, review, release

Status: done
Role: qa
Blocked by: 01, 02
Spec: `../spec.md`

## Scope

AVD `inbils_pixel7` with the real Firebase project: log in, allow notifications, `inbils:push:test` from the web → heads-up shown in foreground, shown in background, tap opens the right screen and marks it read; token deleted on logout; invalid-token cleanup exercised by deleting the app and sending again. Security review (token ownership, no PII in logs, credentials outside repo), full PHPUnit + E2E, CHANGELOG with deploy (`composer install`, `migrate --force`, `FIREBASE_CREDENTIALS` mount, queue restart), commit + push both repos, 8080 refresh.

## Acceptance criteria

- [x] Device evidence (screenshots) + gates green; pushed.

## Comments

### PushDeviceQa device report, 2026-10-17 (device slice only; Status left as is)

AVD `inbils_pixel7` (Pixel 7, API 35, Google APIs, headless), debug dev APKs of `inbils-mobile@fba0cc0` built with a gitignored `env/dev.local.json` (`http://127.0.0.1:8010`) and `adb reverse tcp:8010 tcp:8081`. Fresh install of both apps (`adb uninstall` first), real Firebase project `id-inbils-customer`, dev DB `inbils` via Sail. Nothing was reset or truncated; records were left.

**Why port 8081.** `FcmChannel::for()` runs in `via()` and returns no channel when `FIREBASE_CREDENTIALS` is empty. The Sail web server on :80 has no such variable, so a web action there writes the bell row and never queues a push. For this run a second `artisan serve --no-reload` (port 8081, same code, same DB) was started with the variable, a throw-away host TCP forwarder `127.0.0.1:8081` made it reachable as `localhost` (the `trustHosts` regexes only accept `localhost` and `127.0.0.1`), and both apps plus the admin browser used it. The queue worker ran as `sail exec -u sail -e FIREBASE_CREDENTIALS=… laravel.test php artisan queue:work --queue=default --stop-when-empty --tries=3` after each trigger. The first worker run also drained five stale `default` jobs from 2026-10-15/16 (database channel only; they created bell rows for the customer and the technician; the five `whatsapp` jobs were not touched). Both extra processes, the key copy and the forwarder are gone (see "Cleanup").

**Customer app (user 5, `customer@demo.inbils.test`)**

| Step | Result |
|---|---|
| Login, then Android 13 prompt "Allow Inbils Dev to send you notifications?" | Prompt after sign-in only. `device_tokens` was 0 rows before Allow; after Allow row id 2 (`user_id` 5, `app` customer, `platform` android, bound to Sanctum token 17, token tail `II8TTo`). |
| `inbils:push:test customer@demo.inbils.test --app=customer`, app in foreground | `customer: 1 accepted, 0 stale (deleted), 0 failed.` Heads-up on Beranda 2 s later: "Inbils Demo Company: Inbils / Tes notifikasi push dari server." (ISP prefix added by the app). |
| Same, app on the launcher | Shade shows "Inbils • now / Tes notifikasi push dari server." (system tray draws the raw FCM payload: no ISP prefix). Tap opens Notifikasi ("Belum ada notifikasi": the test push has no subject, so nothing to mark read). |
| Real event 1: admin UI, Pengumuman & Promo, outage "QA push: gangguan jaringan POP-01" targeted at Default POP, Terbitkan; worker run | Jobs: `SendAnnouncementNotifications` then `SendPushNotification` (254 ms). Shade: "Pengumuman baru / QA push: gangguan jaringan POP-01". Tap opens Pengumuman detail; bell row `read_at` NULL → `2026-10-17 02:46:49`. |
| Real event 2: admin created SPK-2026-00051 (Perawatan, E2E-LINKED-SUB), Terbitkan, Penugasan → Tugaskan to Demo Technician; technician app "Saya berangkat"; customer app killed first (`am kill`, pid gone) | `SendPushNotification` 275 ms. Shade: "Teknisi dalam perjalanan / Teknisi Demo sedang menuju lokasi Anda." Tap opens "Kunjungan teknisi" (Ditugaskan 09:52, Dalam perjalanan 09:53, technician "Demo"); bell row read at 02:54:28. |
| Logout (Akun → Keluar → Keluar) | `device_tokens` row 2 deleted; `inbils:push:test customer…` now says "has no install a push can reach: sign in to the app first so it registers its token." |

**Technician app (user 4, `technician@demo.inbils.test`)**

| Step | Result |
|---|---|
| Login, prompt, Allow | Row id 3 (`app` technician, token tail `jR9a5Q`, Sanctum token 18). |
| `inbils:push:test technician@… --app=technician`, foreground | `technician: 1 accepted…`; heads-up "Inbils Demo Company: Inbils / Tes notifikasi push dari server."; tap opens the Notifikasi tab. |
| Real event: Penugasan → SPK-2026-00051 assigned (app on launcher) | Shade "SPK baru ditugaskan / SPK baru sudah ditugaskan kepada Anda."; tap opens SPK-2026-00051 (Ditugaskan, Saya berangkat, Mulai); bell row `spk_scheduled` wo 63 read at 02:52:57. |
| Invalid-token cleanup: `adb uninstall id.inbils.technician.dev`, then push test | First attempt: `technician: 0 accepted, 1 stale (deleted), 0 failed.`; `device_tokens` now 0 rows. |

**Findings**

| Screen | Issue | Severity | Fixed? |
|---|---|---|---|
| Dev stack (Sail :80) | Web actions never queue a push because `FIREBASE_CREDENTIALS` is only set per `sail exec`; `docs/operations/push-notifications.md` Sail section shows only the artisan command. A tester following it sees bell rows and no push. | Medium (docs/dev setup; production compose sets it for every service) | No. Needs a doc line (set the variable in the Sail `.env` or run a credentialed server) — web docs are outside this slice. |
| Customer Beranda | After an outage push the home feed is stale: back from the announcement detail shows no banner until pull-to-refresh. | Low | No (app behaviour; refresh on push or resume would fix it). |
| Notification title | Foreground heads-up has the ISP prefix ("Inbils Demo Company: …"), background/terminated tray notification does not (system draws the FCM `notification` block). | Low (by design, spec N5) | No. |
| Technician SPK detail | Hero line shows the raw type "maintenance · Prioritas sedang"; the customer app shows "Perawatan". | Low | No. |
| Customer bell | Stale `invoice_issued` row (written by the drained 2026-10-15 job) has no subject, so it reads "Buka notifikasi ini untuk melihat rinciannya" and has no target screen. | Info (old payload) | No. |
| Emulator | Host swap was 16.1/16.1 GB (17 orphaned `flutter_tester` processes held 3.6 GB); the emulator died with SIGSEGV mid-run and was rebooted. The 17 orphans (ppid 1, older than one hour, idle) were killed; swap fell to 12 GB. | Info (environment) | Yes (processes killed). |
| Beranda entrance (debug build, software GPU, host under swap) | About 0.47 s gap with the greeting shown and an empty hero slot between skeleton and hero. Not a production measure. | Info | No. |
| Other screens opened | Hero, Langganan, Tagihan, invoice detail (attempt "Kedaluwarsa"), paid invoice detail, ticket detail, Akun theme (Gelap, back to Sistem): no crash, clipping or overflow. | — | — |

**Release gates NOT done here:** security review, full PHPUnit and E2E, CHANGELOG, commit/push, 8080 refresh stay open, so the acceptance box above stays unticked.

**Motion and screenshots (mobile ticket 08)** — `docs/user-guide/images/customer/redesign/`: `motion.webm` (10.2 s, VP9, 428 KB), `beranda-speedmeter-blue-light.png`, `beranda-speedmeter-blue-dark.png`, `detail-tagihan-attempt-blue-light.png` (attempt "Kedaluwarsa"), `detail-tiket-deadline-blue-light.png` (TKT-2026-00047: "Dijawab sebelum 17 Okt 2026 22:12, sisa 11 jam 59 menit" and "Diselesaikan sebelum 18 Okt 2026 10:12"), `detail-tiket-terlambat-blue-light.png` (E2E-DISPATCH-TICKET, "terlambat 4 hari 18 jam"), `detail-langganan-sla-pppoe-blue-light.png` (speed meter, `e2e-linked@demo` with copy button, "Paket SLA Bronze, Respons ≤ 24 jam, selesai ≤ 48 jam"), `unduh-bukti-bayar-share-sheet.png` (Android share sheet "Sharing 1 file: E2E-REC-LINKED.pdf", so the receipt FileProvider path works on a device).

**Push evidence** — `.scratch/push-notifications/evidence/` (11 PNGs): `customer-01…07`, `technician-01…04`.

**Contrast of the new hero** (rendered pixels sampled from device screenshots, ratios from `check_contrast`):

- Light, warning hero: white `#FFFFFF` on `#92400E` 7.09:1; on the wave `#78340B` (payment-method row) 9.15:1; Bayar button `#92400E` on `#FFFFFF` 7.09:1; "Aktif" chip `#14532D` on `#DCFCE7` 8.30:1. All AA.
- Dark, warning hero: `#451A03` on `#FCD34D` 10.39:1; on the wave `#FDDB6D` 11.08:1; chip `#BBF7D0` on `#14532D` 7.52:1. All AA.
- Unfilled dial track and its ticks are decorative: `#B17551` on `#92400E` 1.87:1 (light) and `#C99F38` on `#FCD34D` 1.71:1 (dark), below the 3:1 non-text guideline. The value is carried by the white filled arc and needle (7.09:1), the large number and the spoken label "Kecepatan paket: unduh 10 Mbps, unggah 2 Mbps", so this is reported, not fixed. Only the warning tone was rendered; the danger and brand tones need other data.

**Cleanup:** the Admin SDK key copy `storage/app/private/firebase-service-account.json` was deleted (checked: file absent; the path is ignored by `storage/app/private/.gitignore`). The 8081 server, the forwarder, `adb reverse`, the emulator and `env/dev.local.json` (both apps) are gone; `git status` of `inbils-mobile` is empty. The `google-services.json` copies in `customer/android/app/` and `technician/android/app/` (mode 600) were there before this run; `git check-ignore -v` matches both to `.gitignore:9`. The technician app was left uninstalled from the AVD (invalid-token test).

**Dev DB records left (owner rule):** announcement 1 (outage, deactivated), SPK-2026-00051 (WO 63, assigned, en route), ticket TKT-2026-00047, PPPoE account `e2e-linked@demo` on E2E-LINKED-SUB (created in the admin UI; its one-time password was not recorded), bell rows for customer 21 and technician 4, five drained stale notification jobs. Admin UI only, no tinker writes except deleting my own probe Sanctum token (`qa-probe`) and the deleted device tokens above.

2026-10-17, orchestrator: device smoke with real FCM passed for both apps (evidence in `.scratch/push-notifications/evidence/`); release gates ran in the combined gate of commit 870bc8f (PHPUnit 3781, E2E 262, security reviewed); 8080 rebuilt with `platform-queue`; push stays off on 8080 until the owner sets `FIREBASE_CREDENTIALS_FILE` in the real `.env.docker` (never edited by agents). App-side Low items (stale feed after a push until pull-to-refresh; technician hero raw `maintenance` type) go to the flow audit.
