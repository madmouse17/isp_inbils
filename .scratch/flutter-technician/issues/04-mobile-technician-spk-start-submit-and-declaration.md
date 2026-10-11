# MOBILE: SPK start and submit, material usage declaration (online)

Status: ready-for-agent
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

- [ ] Widget tests:
  - "Mulai" only when `actions.start`; the confirm dialog sends one POST with a key, and a retry after a timeout reuses it;
  - `conflict` triggers a reload;
  - each gating reason appears for its fixture state;
  - "Kirim untuk review" sends one POST and renders `waiting_review`.
- [ ] Declaration tests:
  - negative numbers are blocked;
  - Selisih > 0 requires a code;
  - server errors `lines.0.actual_used_qty` and similar land on the right input;
  - the same key is reused on retry;
  - 409 shows the reload message.
- [ ] Live on the AVD against the compose stack, using a seeded SPK assigned to the technician with an issued inventory request (state in a comment when the seed lacks one, and create it on the web as staff):
  1. Mulai → `in_progress`.
  2. Submit is blocked on missing evidence. Add evidence on the web while ticket 06 is pending.
  3. Declare.
  4. Submit → `waiting_review` on the web.

  Described as seen.
- [ ] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments
