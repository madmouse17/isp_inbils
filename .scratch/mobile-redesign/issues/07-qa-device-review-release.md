# QA: device journeys, review, release

Status: ready-for-agent
Role: qa/reviewer
Blocked by: 01–06
Spec: `../spec.md`

## Scope

Web gates for 01/02 (PHPUnit, build, E2E), mobile `flutter analyze`/`flutter test` in core and both apps, device run on AVD `inbils_pixel7` (customer: login, Home light/dark with two brand colours, pay, Bantuan flow; technician: Home, work order), contrast check with `check_contrast`, review, CHANGELOG (web + mobile), commit + push both repos.

## Acceptance criteria

- [ ] All gates green with evidence and screenshots; pushed.

## Comments

### Device QA report, 2026-10-16

AVD `inbils_pixel7`, Pixel 7/API 35. Customer and technician dev debug APKs used a gitignored `env/dev.local.json` plus `adb reverse tcp:8010 tcp:80`, against the existing Sail dev database. Admin company profile was set through Perusahaan → Profil to `#0F766E`, WhatsApp `6281234567890`, and `Senin–Sabtu 08.00–20.00`. Brand colour was restored through the same form to `#2563EB`; the two contact fields remain as requested.

#### Device paths exercised

- Customer: launcher and splash; login; Beranda teal and blue, light and dark; unpaid invoice list/detail; Bayar opened `app.sandbox.midtrans.com` in Chrome; Hubungi ISP sheet; Langganan detail; Bantuan; Ada masalah? category and checklist; prefilled ticket form; copy ticket number; Akun theme toggle.
- Technician after an AVD reboot: login; Beranda light/dark with company initials badge, next visit, counts, Navigasi and Buka SPK; Pekerjaan; SPK detail; Akun theme toggle. Demo company has no logo, so the documented initials fallback (`ID`) was exercised instead of a network image.

Screenshots: `docs/user-guide/images/customer/redesign/` (17 PNGs) and `docs/user-guide/images/technician/redesign/` (13 PNGs). Names identify screen, colour, and mode; launcher/splash and Midtrans evidence are included.

#### Contrast

Rendered hero pixels matched tokens: warning light `#92400E/#FFFFFF`, warning dark `#FCD34D/#451A03`; screenshots also differed by brand-tinted surfaces between teal and blue. `check_contrast` results:

- Teal primary: light `#FFFFFF` on `#005C55` = 7.89:1; dark `#003733` on `#80D5CB` = 7.71:1.
- Blue primary: light `#FFFFFF` on `#004AC6` = 7.51:1; dark `#002A78` on `#B4C5FF` = 7.72:1.
- Warning hero: light = 7.09:1; dark = 10.39:1.
- Warning badge: light `#78350F` on `#FEF3C7` = 8.15:1; dark `#FDE68A` on `#78350F` = 7.28:1.

#### Findings

| Screen | Issue | Severity | Fixed? |
|---|---|---:|---|
| Customer and technician | No crash, clipped text, or overflow seen on requested paths in either mode. | — | No fix needed |
| Customer Bayar | First external launch stopped at Chrome first-run consent; after choosing “Use without an account”, sandbox Midtrans loaded. App redirect worked. | Info | Environment setup |
| Technician Beranda | Demo company has no logo, so the ISP badge correctly used initials `ID`; real raster logo download remains unexercised. | Low | No; fixture limitation |
| Customer help hint | Demo categories have no `customer_hint`, so generic three-step checklist rendered; custom web-authored hint was not available in dev data. | Low | No; fixture limitation |
| Web/API during QA | Concurrent edit briefly introduced a `HomeFeedController.php:23` parse error, breaking API route boot; owner agent fixed it and device requests resumed. | High/transient | Yes, outside this QA slice |
| Test cleanup | QA invoice `INV-2026-00128` and pending sandbox attempt remain in dev DB. Invoice policy blocks its creator from cancelling it. | Low | No; needs another authorized web user or expiry/reconciliation |

No mobile production defect was found, so no mobile source or test file changed.

#### Verification

- Customer: `flutter analyze --no-pub` → `No issues found!`; `flutter test --no-pub --reporter compact` → `+250: All tests passed!`.
- Technician: `flutter analyze --no-pub` → `No issues found!`; `flutter test --no-pub --reporter compact` → `+244: All tests passed!` (debug-only secure-storage warning printed during tests).
- APK builds: customer and technician `flutter build apk --debug --flavor dev --dart-define-from-file=env/dev.local.json` → built `app-dev-debug.apk`.
- Device smoke: both apps logged in through Sail API on the rebooted emulator; customer Midtrans redirect reached `app.sandbox.midtrans.com`; theme selectors reported the selected mode through Android semantics.

Not part of this device slice: core package test/analyze, web PHPUnit/E2E, CHANGELOG, commit, or push. Status remains for orchestrator.
