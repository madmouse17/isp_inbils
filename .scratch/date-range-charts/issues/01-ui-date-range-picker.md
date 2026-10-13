# UI: DateRangePicker primitive, date helpers, chart wrappers

Status: done
Role: ui
Blocked by: none
Spec: `../spec.md`

## Scope (owned files)

- New `resources/js/Components/ui/DateRangePicker.tsx` (+ export in `Components/ui/index.ts`), per the picker contract and R1/R3 in the spec. Uses `@radix-ui/react-popover` (existing `ui/popover.tsx` if there is one), `react-day-picker` v10 range mode with `date-fns/locale/id`, presets list (Q2) computed in the company timezone.
- New `resources/js/lib/dateFormat.ts` (PHP → date-fns token mapper, `phpToDateFns(format)`), `resources/js/lib/dateRange.ts` (parse/format a range in a given format, validate, preset computation, `todayInZone(tz)`), and one node selfcheck `resources/js/lib/dateRange.selfcheck.mjs` (mapping table incl. `d M Y`, `d/m/Y`, `Y-m-d`, fallback; parse of typed input round-trips; inverted range rejected; presets at a Jakarta instant near midnight UTC).
- `resources/js/lib/format.ts`: `formatDateTime(value, company?)` honours `date_format` (R7); `formatDate` unchanged.
- Chart wrappers `resources/js/Components/ui/Chart.tsx` per contract (recharts, `accessibilityLayer`, empty state R4, colours from `--chart-1..6`), plus `resources/js/Components/ui/ChartLazy.tsx` exporting `React.lazy` versions with a skeleton fallback. Declare `--chart-1..6` in `resources/css/app.css` `@theme` with light values and `dark:` overrides (readable on both; check contrast of lines on surface).
- Delete `resources/js/Components/composite/DateRangeFilter.tsx` and its export.
- react-day-picker styles: import its CSS once in `resources/css/app.css` (or `app.tsx`) and restyle day cells with Tailwind tokens (`bg-brand`, focus ring, 44 px tap targets).

## Acceptance criteria

- [x] Selfcheck passes; `tsc`, ESLint clean.
- [x] Picker: typing `13/10/2026 - 20/10/2026` (company format) emits ISO; invalid or inverted shows error and emits nothing; calendar range selection and presets emit ISO; Escape returns focus to the trigger; one month below `sm`.
- [x] Chart wrappers render with data and show the empty state without data (checked in a Storybook-free way: a throwaway page is fine, do not commit it).

## Comments

### FeCore, 2026-10-13

Evidence (all run from the repo root unless noted):

- `node resources/js/lib/dateRange.selfcheck.mjs` under `TZ=Asia/Jakarta`, `UTC`, `America/Los_Angeles`, `Pacific/Kiritimati` → `tests 12, pass 12, fail 0` each. It covers the mapping table (`d M Y`, `d/m/Y`, `Y-m-d`, `j F Y`, `n/j/y`, `D, d M Y`, `l, j F Y`, fallbacks), typed input round-trips in six formats, the ticket's `13/10/2026 - 20/10/2026`, half ranges, rejections (garbage, `31/02`, two-digit year, three parts, inverted), presets from `2026-10-12T17:30:00Z` in `Asia/Jakarta` (= 13 Okt), month/leap/year boundaries, and `formatDate`/`formatDateTime`.
- `npx tsc --noEmit` → exit 0. `./node_modules/.bin/eslint` on the 8 touched ts/tsx files → exit 0. `prettier --check` on the touched ts/tsx/mjs/css → clean.
- Real Chromium (Playwright 1.61) against a throwaway Vite build of the real components + real `app.css` (harness in `/tmp`, deleted; not committed). The harness used a stub for `usePage` only:
  - typed `13/10/2026 - 20/10/2026` (`d/m/Y`) → exactly one emission `{"from":"2026-10-13","to":"2026-10-20"}` on blur, none while typing; `13 Okt 2026 - 20 Oktober 2026` (`d M Y`) → same ISO.
  - `bukan tanggal` → `aria-invalid="true"`, `role=alert` "Format tanggal tidak valid. Contoh: 07/10/2026 – 13/10/2026.", nothing emitted. `20/10/2026 - 13/10/2026` → "Tanggal akhir tidak boleh sebelum tanggal mulai.", nothing emitted.
  - Enter with a pending entry commits and does not submit the surrounding `<form>`; the second Enter submits.
  - calendar: first click emits nothing (hint "Pilih tanggal akhir."), second click emits `{from,to}` and closes, focus returns to "Buka kalender"; reversed clicks emit an ordered range; same day twice = one-day range; keyboard-only pick (Space, arrows, Enter, arrows, Enter) works.
  - presets `Hari ini`, `7 hari terakhir`, `30 hari terakhir`, `Bulan ini`, `Tahun ini` emit the expected ISO for the company timezone.
  - Enter on the trigger opens, Escape closes, focus back on the trigger. Dialog role `dialog`, name "Pilih rentang tanggal". Two grids at 1280, one at 375 (touch), popover inside the viewport, no page overflow, day cells 44x44 at 375 (41x41 at 320, where seven 44px cells do not fit).
  - charts at 1280 and 375, light and dark: four `svg[aria-label]` (role application, tabindex 0), arrow keys move the tooltip, "Tidak ada data pada periode ini" for `[]` and for all-zero data, currency tooltip `Rp 8.750.000`, no horizontal scroll, no console warnings.
- Contrast: chart colours `--chart-1..6` are 3.6 to 5.7 on white (light) and 7.4 to 11.1 on `#020817` (dark), WCAG formula; `check_contrast` agrees on `#ea580c`/white 3.56, `#60a5fa`/`#020817` 7.87, white/`#2563eb` 5.17. Chart text uses foreground tokens, never the series colour.

Decisions and deviations:

- `formatDate` was NOT left unchanged: it only replaced the first `d`, `m`, `Y`, so the seeded `d M Y` printed `13 M 2026`. Both `formatDate` and `formatDateTime` now format through `phpToDateFns` + date-fns with the Indonesian locale; ISO `yyyy-mm-dd` is read as a calendar day (`new Date('2026-10-13')` is the 12th west of Greenwich); `d/m/Y` output is identical to before; formats with unsupported tokens (`H:i`, `S`, quotes) now print `dd/MM/yyyy` instead of a half-replaced string. Affects the 66 `formatDate` call sites only when the company format is not `d/m/Y`-like.
- The typed/printed locale is a derived `localeId` in `lib/dateFormat.ts`: date-fns says "Agt" for August, Intl/CLDR/Carbon say "Agu". Output is "Agu"; typing "Agt" or "Agustus" is still accepted. The calendar uses `react-day-picker/locale/id` (date-fns `id` plus Indonesian ARIA labels: "Ke bulan berikutnya", "Hari ini, ..., dipilih").
- Range text grammar: output `13 Okt 2026 – 20 Okt 2026` (en dash); typing accepts ` - `, `–`, `—`; one date alone is that day; a dangling separator is a half range (`13/10/2026 -` = from, `- 20/10/2026` = to); blank clears; four-digit years only.
- Presets: `7/30 hari terakhir` count through today (today-6, today-29); `Bulan ini` and `Tahun ini` end today; `Bulan lalu` is the whole previous month.
- Day cells are 44px at every width; they shrink only where the months cannot fit (320px phone: 41px; 640-700px two-month window: 41-44px). Presets sit above the calendar below `lg`, beside it from `lg`.
- Chart tokens are `@theme static` (light) plus `.dark` overrides; `@theme` alone would drop them because no utility names them.
- Not lazy: the calendar. The picker adds about 31 KB gzip over a React + Radix baseline on every page that uses it (measured with a throwaway Vite build); recharts (about 117 KB gzip) loads only through `ChartLazy`, and the `ui` barrel never imports `Chart.tsx` (checked on the transpiled barrel).
- Stale doc, not mine: `docs/plans/INBILS_FULL_SHADCN_COMPONENT_MATRIX.md` still lists `DateRangeFilter.tsx` (needs-change for ticket 06). `CHANGELOG.md` has no entry for this lane yet (ticket 06).
- Not run, per the lane rules: `npm run build`, E2E. The built CSS of the harness shows the vendor `react-day-picker/style.css` import resolves and both chart token sets are emitted.
