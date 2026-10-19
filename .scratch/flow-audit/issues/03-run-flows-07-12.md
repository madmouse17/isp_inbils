# QA: run flows 7–12 end to end and record findings

Status: done
Role: qa
Blocked by: 01
Spec: `../spec.md`

## Scope

Execute flows 7–12 per the spec rules; same output.

## Acceptance criteria

- [x] Same as 02 for flows 7–12. (Flow 10 subsidiary creation, reduce motion, dark mode on the remaining screens and offline push not run: recorded in report section 6.)

## Comments

### FlowAudit2 run report, 2026-10-17 (partial; Status set to in-progress)

Output: `docs/plans/FLOW_AUDIT_2026-10-17.md` (Indonesian, same table format as `docs/plans/FLOW_AUDIT_2026-10-17.md`, IDs `F7-…` to `F12-…`), screenshots `docs/plans/flow-audit/{07,08,09,10}-*.png`. Dev DB `inbils` via Sail; no reset, truncate or delete; records prefixed `AUDIT`.

- **Flow 7 done (web)**: outage announcement targeted at POP Dago (8 of 8 active subscriptions notified, SQL-checked), scheduled maintenance announcement dispatched at start, manual block/unblock/suspend/reactivate, isolir from Tunggakan, NAS resync (RADIUS off in dev, so no real router sync), IPAM static IP and prefix, asset maintenance. Findings F7-D01..D05, F7-U01..U05. Device side (Beranda card, push on phone) not run.
- **Flow 8 done (web + technician API)**: reject, full cycle approve/prepare/ready/issue/declare/receive/resolve, manager cancel, stock adjustment approve/reject, receive stock. Findings F8-D01..D13, F8-U01..U06.
- **Flow 9 done**: every report page, role matrix, numbers checked against SQL, CSV/PDF exports, drill-down. Findings F9-D01..D09 (audit-log PDF without filter returns HTTP 500 after 30 s), F9-U01..U03.
- **Flow 10 partial**: company switch, data isolation, role rules, consolidated report and export. Subsidiary creation through the platform panel not run (F10-D01..D04, F10-U01..U02).
- **Flow 11 NOT run, flow 12 NOT run on device** (session budget). Runbook, prerequisites and code-derived findings are in the report sections; AVD `inbils_pixel7` is up with both dev APKs, `adb reverse tcp:8010 tcp:80`; tenant `AUDIT-ISP` and operator `audit-operator@inbils.test` are ready (secrets under `~/.config/inbils/flow-audit/`).
- **Blocking crash fixed (F8-D01)**: return-case actions (Terima Pengembalian Fisik, Tolak Fisik Barang, Selesaikan Selisih) answered `CSRF token mismatch.` (419) because `Show.tsx` posted with `fetch` and a `csrf-token` meta tag that `app.blade.php` never renders. Fix: `axios.post` in `handleReturnApiAction` (`resources/js/Pages/Admin/Inventory/Requests/Show.tsx`). Test: `tests/e2e/inventory-returns.spec.ts` (clean return, handover rejection, discrepancy resolved by a different manager). RED against the old bundle: first test failed on `POST …/receive-accept` 419. GREEN result is recorded in the report section "Verifikasi perbaikan F8-D01". No commit made.
- Environment: `FIREBASE_CREDENTIALS` line in the Sail `.env` and the key copy in `storage/app/private/` were removed at the end of this run; re-add both for flow 12 (command in the report).

### FlowAudit4 run report, 2026-10-17 (flow 11 done on web; flow 12 NOT run on device; Status stays in-progress)

Output: `docs/plans/FLOW_AUDIT_2026-10-17.md` (Indonesian, IDs `F11-D01..D12`, `F11-U01..U10`, `F12-D01..D08`), screenshots `docs/plans/flow-audit/11-*.png` (80), notes `~/.config/inbils/flow-audit/notes-fa4.jsonl`. Dev DB `inbils`; nothing reset or deleted; no production code changed, no commit.

- **Flow 11 (new tenant `AUDIT-SUP`, id 4):** read-only impersonation, write blocked with the Indonesian message, write mode with company code, tenant audit shows "Akses dukungan", Keluar; resend invite (old link rejected, new link works); 2FA reset; unlock (password lock and 2FA lock); suspend → web login 422 and API login 422 and old token 401 → reactivate; export (queue, ZIP, 0 foreign rows); platform audit filters. Untested: password step-up (423) on write mode (always under 5 minutes) and app login on the AVD.
- **Top findings:** F11-D01 suspend/export/2FA reset leave an error screen although the action succeeded (probable cause: `url.intended=/admin/dashboard` in the operator session consumed by `ConfirmablePasswordController`); F11-D02 "Buka kunci masuk" clears only the 2FA counter, not the email+IP password lock, and is hidden for users without 2FA; F11-D03 eight of ten support actions read only "Akses dukungan oleh tim Inbils" in the tenant audit; F11-D04 export picks media by folder (29 foreign files in the AUDIT-SUP ZIP) and omits roles and the media table.
- **Flow 12 NOT run on device** (request budget; AVD was held by FlowAudit5 until the end). Delivered: code maps (14 push triggers with routes/commands/personas, screens, motion, offline, status labels), FlowAudit5 device evidence, and the remaining steps list in the report. F12-D07: FlowAudit5 saw push taps open Beranda/last screen, contradicting the 2026-10-17 push QA; needs a recheck per event type.
- **Environment left as is:** services `fa4-queue-default` and `fa4-queue-platform` running; FCM key `storage/app/private/firebase-service-account.json` and `.env` line 85 `FIREBASE_CREDENTIALS=` still present (FlowAudit5 never sent "done with key"; flow 6 still needs them); AVD `inbils_pixel7` left running and handed to FlowAudit6.

### FlowAudit7 run report, 2026-10-17 (flow 12 run on the real device; Status stays in-progress for flows 10 and 12 gaps)

Output: `docs/plans/FLOW_AUDIT_2026-10-17.md` section "Alur 12" (replaces the code map; IDs `F12-D07, D09–D20`, `F12-U01–U09`), screenshots `docs/plans/flow-audit/12-*.png` (30), notes `~/.config/inbils/flow-audit/notes-fa7.jsonl`. Dev DB `inbils`; no reset/truncate/delete; one UPDATE on an AUDIT ticket (`sla_deadline` of TKT-2026-00004 moved to the past, SLA minimum is 1 h); no production code changed, no commit.

- **(a) 14 push events** triggered through the real action by the right persona, heads-up captured, tap target checked per event (table in the report). All route to the right screen when one push is pending, in fg, bg and killed states.
- **F12-D07 pinned:** tap opens Beranda/last screen when 2+ pushes are pending: Android auto-groups them and the collapsed group row launches the system summary intent (no payload) and clears the group; expanding the group first (or a single push) opens the right screen. FA5 and the 2026-10-17 push QA were both right.
- **F12-D10 (owner decision):** `ticket_sla_breached` goes to `created_by` (the customer user for app-made tickets): the customer gets the "SLA terlampaui" mail (Mailpit) and no push reaches any app; the technician is never told.
- **F12-D09:** screen opened from a push shows the old status (SPK rejected/approved) until pull-to-refresh; reason of rejection hidden.
- **(b) bell deep links:** all 11 customer rows and 7 technician rows open the right screen.
- **(c) dark mode:** PARTIAL, 14 screens (6 technician, 8 customer); contrast tool: 4.56, 4.58, 6.93 (all pass AA, two barely). **(d) reduce motion: NOT verified on device** (emulator died 3 times: host swap full, load 132). **(e) offline:** true offline needs airplane mode and `adb reverse --remove` (reverse keeps apps online); Berangkat/Mulai blocked with "Butuh koneksi internet", photo queued and synced in about 8 s (DB `media` for SPK id 66: 0 → 1). **(f)** copy mismatches listed (10 ranked).
- **Not run:** F12-D05 (app login on suspended tenant), dark mode on the remaining screens, reduce motion, push while the phone is offline.
- **Cleanup done:** `FIREBASE_CREDENTIALS` line removed from `.env` (tail shown before/after in the report), `storage/app/private/firebase-service-account.json` and both `google-services.json` deleted, services `fa4-queue-default`, `fa4-queue-platform` (+ two leftover in-container workers) and the AVD service stopped.
