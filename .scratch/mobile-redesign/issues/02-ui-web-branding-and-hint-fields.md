# UI (web): brand colour, WhatsApp, support hours on Company page; customer hint on Kategori Tiket

Status: done
Role: ui
Blocked by: 01; ui-consistency 09 merged (same Company Settings page)
Spec: `../spec.md`

## Scope (owned files)

Company profile page: brand colour field (8 presets as swatches + hex input, live contrast check against white text using the existing contrast helper or a small pure function with a selfcheck), WhatsApp and support hours inputs; `Pages/Admin/Tickets/Categories/Index.tsx`: "Saran untuk pelanggan" textarea (≤ 500, counter). Components from `Components/ui` only, dark mode, Indonesian.

## Acceptance criteria

- [x] `tsc`, ESLint, selfchecks clean; existing company/category E2E specs updated.

## Comments

2026-10-15, BrandUi. Done; nothing committed.

**Shipped**

- `resources/js/lib/contrast.ts` (`contrastWithWhite`, `isHexColor`, `MIN_TEXT_CONTRAST = 4.5`, pure) plus `contrast.selfcheck.mjs` (4 tests: ends of the scale, the 8 preset ratios, case, malformed input).
- `Pages/Admin/Company/Profile.tsx`: `BrandColorField` (8 preset swatches as native radios in a `radiogroup`, selected ring, hex input kept in sync and compared case-insensitively, sample chip and live contrast line, amber warning below 4,5:1), `PhoneInput` "WhatsApp", `Input` "Jam layanan" (max 120). Submits through the existing `post` with `_method: 'put'`; empty string clears. `Company` type gained `whatsapp`, `support_hours`, `brand_color`.
- `Pages/Admin/Tickets/Categories/Index.tsx`: `Textarea` "Saran untuk pelanggan", `maxLength` 500, counter in the hint, `errors.customer_hint`. Page added to `CONVERTED` in `copy.selfcheck.mjs`.
- E2E (type-checked only, not run): `radius-provisioning.spec.ts` company steps fill, verify after reload and restore the three fields; `sla-targets.spec.ts` new test saves, caps (500), reloads and restores the hint.

**Evidence**

- `npx tsc --noEmit` → exit 0. ESLint on touched files → 0 errors, 0 warnings (the `.mjs` selfcheck is in the ignore list). `npx prettier --write` on touched files → clean.
- `node --test resources/js/lib/*.selfcheck.mjs` → 80 tests, 80 pass, 0 fail (incl. contrast and copy).
- The two specs: `tsc --strict` shows only a pre-existing `Promise.withResolvers` lib error at `radius-provisioning.spec.ts:958`.

**Not verified**

- No rendered page check, no `npm run build`, no E2E run (orchestrator runs them). Swatch focus ring and dark mode are untested in a browser.
- Presets `#EA580C`, `#16A34A`, `#0891B2` are under 4,5:1 with white (3,56, 3,30, 3,68), so picking them shows the warning by design; the save is not blocked.
