# MOBILE: SPK start and submit, material usage declaration (online)

Status: done
Role: mobile
Blocked by: 03
Cross-lane blocked by: mobile-api 05 (start, submit), mobile-api 06 (material returns)
Card: none (new lane; no Hermes card)
Skills: antislop-ui, antislop-human, antislop-layoutmobile, tdd, ponytail, caveman

## Scope

Implements the SPK state actions and the "Deklarasi material" screen (API Q13a).

- SPK detail action bar:
  - "Mulai" when `actions.start` (confirm dialog) → `POST technician/work-orders/{id}/start`.
  - "Kirim untuk review" when `actions.submit` → `POST …/submit`.
  - Each action uses one idempotency key per tap and its retries, disables while in flight, and on success re-renders from the returned detail.
  - On `conflict` or `unprocessable`, re-read the SPK and show the server message.
- Submit gating, shown as text under the disabled button:
  - "Belum ada foto bukti" when the evidence list is empty;
  - "Deklarasikan material dulu" when a case is `awaiting_declaration` or `correction_required`;
  - after ticket 07: "Tunggu N foto terkirim" when the queue has rows for this SPK.
- Deklarasi material (`/material-returns/:id`, from the SPK section):
  - `GET technician/material-returns/{id}`, with one block per line and the inputs Terpakai, Kembali baik and Selisih (numbers ≥ 0, decimal keyboard).
  - When Selisih > 0: a code (`damaged` "Rusak" / `lost` "Hilang" / `other` "Lainnya") and a reason, plus an optional overall reason.
  - Submit `POST …/declare` with one idempotency key (a UUID, which fits the server's 64-character key).
  - 422 field errors map to their line inputs (`lines.N.field`). 409 `conflict` shows "Deklarasi sudah dikirim dengan nilai berbeda. Muat ulang." and reloads.
  - On success, go back to the SPK and refresh it.
- Online only: offline shows "Butuh koneksi internet" on these actions.

Non-goals: queued declarations (Q1a), editing SPK items, approve or reject.

## Owned files

- `../inbils-mobile/technician/lib/features/work_orders/` (action bar, gating), NEW `../inbils-mobile/technician/lib/features/material_returns/`
- `../inbils-mobile/technician/lib/app/router.dart`, `../inbils-mobile/technician/lib/l10n/`, `../inbils-mobile/technician/test/`

## Acceptance criteria

- [x] Widget tests:
  - "Mulai" only when `actions.start`; the confirm dialog sends one POST with a key, and a retry after a timeout reuses it;
  - `conflict` triggers a reload;
  - each gating reason appears for its fixture state;
  - "Kirim untuk review" sends one POST and renders `waiting_review`.
- [x] Declaration tests:
  - negative numbers are blocked;
  - Selisih > 0 requires a code;
  - server errors `lines.0.actual_used_qty` and similar land on the right input;
  - the same key is reused on retry;
  - 409 shows the reload message.
- [x] Live on the AVD against the compose stack, using a seeded SPK assigned to the technician with an issued inventory request (state in a comment when the seed lacks one, and create it on the web as staff):
  1. Mulai → `in_progress`.
  2. Submit is blocked on missing evidence. Add evidence on the web while ticket 06 is pending.
  3. Declare.
  4. Submit → `waiting_review` on the web.

  Described as seen.
- [x] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments

### 2026-10-12: done (agent MobTech04)

Commit `9fe4cba` in `inbils-mobile` (not pushed). `dart format --set-exit-if-changed .` clean, `flutter analyze` 0 issues, `flutter test` 53/53 green in `technician` (22 new: `test/work_order_actions_test.dart`, `test/material_return_test.dart`, shared fixtures in `test/support/spk_harness.dart`).

What landed: `features/work_orders/work_order_actions.dart` (bottom action bar, 56 dp buttons, confirm dialog for Mulai, gating text under the disabled "Kirim untuk review"), `attempt_key.dart` (one key per tap, reused after timeout/offline/5xx/429, new key when the payload changes), `WorkOrderDetailController` (a notifier replacing the functional provider, so an action's answer is rendered without a second GET), `features/material_returns/` (model, repository, `/material-returns/:id` screen), route + ARB strings. A conflict or unprocessable answer shows the server message and re-reads the SPK; offline shows "Butuh koneksi internet". The "Deklarasi" button sits on the SPK material-returns row when the case is `awaiting_declaration` or `correction_required`. The queue gate ("Tunggu N foto terkirim") waits for ticket 07, as the ticket says. No `check_in` is sent on start (ticket 11 adds GPS).

Live (AVD `inbils_pixel_api35`, dev flavor, `tests/e2e/setup.php` then a throwaway seed of SPK-2026-35197 assigned to the demo technician with an issued inventory request and its case #12, Laravel `APP_ENV=e2e` on 8011 via `adb reverse`, under `/tmp/inbils-phpunit.lock`), as seen:
1. Login → Pekerjaan → SPK showed "Ditugaskan" and "Mulai". The confirm dialog "Mulai SPK ini?" → Mulai: status "Dikerjakan", "Dimulai 12 Okt 2026 09:45"; the DB read `in_progress`.
2. "Kirim untuk review" was disabled with "Belum ada foto bukti" and "Deklarasikan material dulu" under it. I added an evidence file on the web (tinker); after pull-to-refresh only "Deklarasikan material dulu" remained.
3. Deklarasi (case #12, "Dikeluarkan 10 …"): Terpakai `-1` + Kirim → "Isi angka 0 atau lebih." and no request. Selisih 1 showed the Rusak/Hilang/Lainnya chips; Terpakai 7, Kembali baik 2, Selisih 1, Rusak, reason "Kabel terkelupas" → back on the SPK, case `awaiting_receipt` in the DB, submit enabled.
4. Kirim untuk review → "Menunggu review"; the DB read `waiting_review`.

Not exercised live: the 409 and 422 declaration paths and the timeout retry (widget tests only); the server's 422 text language.

Notes: the case status `awaiting_receipt` shows raw in `WorkStatusChip` (ticket 03 file, outside this ticket): add "Menunggu penerimaan" there. `AttemptKey` is a copy of the customer app's; both belong in `inbils_core` (needs a core change). Teardown: API server, AVD, `adb reverse`, lock holder stopped; `env/dev.local.json` and temp scripts deleted; no tokens recorded.
