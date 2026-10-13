# UI: Penomoran editor and automatic code fields

Status: done
Role: ui
Blocked by: none (builds against the props in 01 and 02)
Spec: `../spec.md`

## Scope

- **Penomoran page** (`resources/js/Pages/Admin/NumberSequences/Index.tsx`): replace the read-only table with an editor.
  - One row per entity: label, current template, preview and reset period, plus an "Ubah" action that opens a dialog with prefix, template, next number, reset period and allow_manual.
  - Token chips insert a token into the template.
  - A live preview, rendered by a small pure helper `resources/js/lib/numberTemplate.ts` with a node selfcheck that mirrors the PHP cases. The server stays the authority on validation errors.
  - The warning "Perubahan format hanya berlaku untuk data baru."
  - Built from `Components/ui/*` + `composite/*` only.
- **New composite** `SequenceCodeField`:
  - Props: label, value, onChange, error, `sequence: { manual; preview }`, `mode: 'create' | 'edit'`.
  - Locked: the field is read-only. In create mode it shows the hint "Dibuat otomatis saat disimpan, perkiraan: {preview}". In edit mode it shows the stored code, and the form must not submit it.
  - Manual: the field is optional, with the preview as placeholder and the hint "Kosongkan untuk dibuat otomatis".
- **Forms that use it**: customer create/edit, the employee dialog, and product create/edit (plus `Pages/Admin/Products/Create.tsx` if it is routed).
- **Types**: `types/models.d.ts`.
- **E2E**:
  - New spec `tests/e2e/number-sequences.spec.ts`: edit a sequence and see the preview, create a customer without a code and get the configured format, create one product with a typed SKU and one without.
  - Update the existing specs that fill these code fields.

## Acceptance criteria

- [x] `tsc`, ESLint and the selfcheck are clean.
- [x] The E2E spec passes in the canonical run.
- [x] Dark mode and keyboard access work on the dialog and the token chips.

## Comments

- 2026-10-13 SeqUi: implemented. `npx tsc --noEmit` exit 0; `eslint resources/js --quiet` exit 0; `node resources/js/lib/numberTemplate.selfcheck.mjs` → `numberTemplate selfcheck ok`; e2e specs type-check with `tsc --strict` (no errors).
- Not proven: the E2E run (`tests/e2e/number-sequences.spec.ts`, plus customer-code removal in customer-subscription, ipam-odn, kanban-t_49387795, whatsapp-notifications; `support/demo.ts createCustomer` skips a locked code field) and the rendered dark-mode/keyboard check. The orchestrator runs build + E2E.
- `types/models.d.ts` does not exist; the types live in `types/number-sequence.d.ts` and `Components/composite/SequenceCodeField.tsx` (`CodeSequence`). `Components/composite/CustomerDataFields.tsx` also changed because it owns the customer Code input.
- `Pages/Admin/Products/Create.tsx` is dead: no route or controller renders `Admin/Products/Create` (Inventory renders `Admin/Inventory/Products/*`). Left untouched.
- 2026-10-13 (orchestrator): canonical chromium run 193 passed, 4 skipped, 1 failed. The failures were in the new spec only and are fixed: `getByLabel('Format')` also matched the "Token format" group (now `getByRole('textbox')`); the edit test moved on before the save redirect finished (now waits for the URL); the product form label is `Name *` (regex). The product test then exposed a pre-existing 500, `min_stock cannot be null`, fixed in `ProductController::store`. Rerun: `number-sequences` + `inventory-lifecycle` 6/6, `number-sequences` + `customer-subscription` green. Keyboard: the spec focuses a chip and presses Enter. Dark mode: screenshot of the dialog on port 8080 after the image rebuild, legible labels, chips, toggle and preview.
