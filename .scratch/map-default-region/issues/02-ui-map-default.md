# UI: Peta section on Company Settings, useMapDefault, map components

Status: done
Role: ui
Blocked by: none (contract in spec D2–D4)
Spec: `../spec.md`

## Scope (owned files)

- `resources/js/Pages/Admin/Company/Settings.tsx` (or the page rendered by `admin.company.settings.edit`): "Peta" section per D2 (SearchSelect of `cities` prop, lat/lng inputs, zoom, `MapPicker` preview that follows the values; manual edit clears `map_city_code`).
- New `resources/js/hooks/useMapDefault.ts` + pure helper + node selfcheck; `types` for `company.map` in `resources/js/types/index.ts`.
- `Components/ui/MapPicker.tsx` (`defaultCenter?`, `defaultZoom?`), `Components/composite/LatLngMapPicker.tsx` + `latLngMapPicker.helpers.ts` (drop `JAKARTA_CENTER`, default comes from props), `Components/composite/locations/LocationsMapView.tsx`, `Components/composite/CustomerAddressEditor.tsx`, `Pages/Admin/Customers/Create.tsx`, `Pages/Admin/CustomerAddresses/Index.tsx` (pass the hook's values).
- E2E: new `tests/e2e/map-default.spec.ts` per the spec (Surakarta → customer create map centred there; restore the previous value at the end). Expose the picker's current centre as `data-center="lat,lng"` on the map container for the assertion.

## Acceptance criteria

- [x] `tsc`, ESLint, selfcheck clean; E2E spec type-checks (orchestrator runs it).

## Comments

Evidence (2026-10-14, MapFe):

- `npx tsc --noEmit` → no output (clean). `./node_modules/.bin/eslint` on every touched `resources/js` file → no output (clean). `npx prettier --write` on touched files → formatted.
- `node resources/js/hooks/mapDefault.helpers.test.mjs` → `all assertions passed`. `node resources/js/Components/composite/latLngMapPicker.helpers.test.mjs` → `all assertions passed` (updated: no `JAKARTA_CENTER`, the default is the caller's).
- `tests/e2e/map-default.spec.ts` type-checks (`tsc --noEmit --strict … tests/e2e/map-default.spec.ts` → clean). Not run: orchestrator runs it (`npm run build` first).

Changes:

- `hooks/useMapDefault.ts` + `hooks/mapDefault.helpers.ts` (+ `.test.mjs`); `types/index.ts` gains `CompanyMap` and `Company.map?`.
- `ui/MapPicker`: `defaultCenter?`/`defaultZoom?` (fallback = all of Indonesia), publishes `data-center="lat,lng"` on its map container on every `moveend`. Own coordinates still win (marker effect `setView` unchanged).
- `composite/LatLngMapPicker`: `defaultCenter` required, `JAKARTA_CENTER` removed from helpers, new `zoom?`/`onZoomChange?` (controlled zoom), `data-center` too. It has no callers besides the new Settings preview (the spec's "used by Customers/Create" was wrong; those use `MapPicker`).
- `LocationsMapView`: first view and "fit" with no markers use the hook; markers still win.
- `CustomerAddressEditor`, `Customers/Create`, `CustomerAddresses/Index` pass the hook's values to `MapPicker`.
- `Pages/Admin/Company/Settings.tsx`: "Peta" card (SearchSelect "Wilayah default" from `cities`, Lintang/Bujur/Zoom inputs, `LatLngMapPicker` preview that follows the values and reports zoom back). Picking a city fills lat/lng + zoom 12; a manual lat/lng edit or map click clears `map_city_code`. `map_*` keys leave the generic list. Keys the company never set show the resolved `company.map`; they are written (all four) only after an edit. Emptying lat/lng/zoom saves nulls = back to the system default.
- `tests/e2e/map-default.spec.ts`: pick KOTA SURAKARTA → preview follows → manual edit drops the label → out-of-range refused → save → Customers/Create address map centred on Surakarta → click a point → map centres there; `finally` restores the previous values.

Needs from backend (MapBe): `E2eFixtureSeeder` must hold KOTA SURAKARTA (lat ≈ −7.56, lng ≈ 110.82) in `cities`; the validation message the spec expects is Laravel's default `… map lat field must be between …`.
