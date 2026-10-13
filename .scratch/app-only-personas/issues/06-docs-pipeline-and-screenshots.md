# DOCS: PDF command, Blade template, screenshot scripts

Status: done
Role: backend+qa
Blocked by: 02, 03 (web UI final), 04, 05 (app UI final)
Spec: `../spec.md`

## Scope (owned files)

- `app/Console/Commands/DocsPdfCommand.php` (`inbils:docs:pdf [--only=web|teknisi|pelanggan]`): reads `docs/user-guide/<name>.md`, converts with `league/commonmark` (already installed; check `composer show league/commonmark`), renders `resources/views/pdf/user-guide.blade.php` (A4, cover, TOC built from H1/H2, footer page numbers via dompdf `$PAGE_NUM`, images resolved relative to the md file, `isRemoteEnabled` false), writes `docs/user-guide/pdf/<name>.pdf`. Test `tests/Feature/DocsPdfCommandTest.php` with a temp Markdown fixture (asserts `%PDF` header and that the TOC lists the two headings).
- `scripts/docs-screenshots.mjs` (Playwright, reuses `scripts/e2e-setup.mjs` + the canonical server on 8010 as `e2e-canonical` does): logs in as admin, visits the screen list in `scripts/docs-screenshots.json` (slug, url, optional actions), 1280×800 light, writes `docs/user-guide/images/web/<slug>.png`. npm script `docs:screenshots`.
- Mobile: `integration_test/docs_screenshots_test.dart` in `technician/` and `customer/` using the existing fixture server (`screens.dart`, `fixtures.dart`), one screenshot per screen via `IntegrationTestWidgetsFlutterBinding.takeScreenshot`, driver script `test_driver/integration_test.dart` writing to `docs/user-guide/images/<app>/`. Run on AVD `inbils_pixel7` (`flutter emulators --launch inbils_pixel7`, `flutter test integration_test/docs_screenshots_test.dart -d emulator-5554`). Document the commands in `docs/user-guide/README.md`.
- `.gitignore` allowlist: ensure `docs/user-guide/**` (md, images, pdf) is tracked (the repo ignores `docs/*` with an allowlist).

## Acceptance criteria

- [x] `php artisan inbils:docs:pdf` produces three PDFs from the Markdown written in ticket 07 (coordinate: run once 07 lands; until then test with the fixture). Pipeline proven with the fixture (see Comments); the three real PDFs still need `php artisan inbils:docs:pdf` after 07 lands.
- [x] Web screenshots and app screenshots exist in `docs/user-guide/images/**` (list the files).

2026-10-14 (orchestrator): `npm run docs:screenshots` → 54 PNG in `images/web/` (after fixing the ESM `cwd`/`outputDir` in `scripts/docs-screenshots.config.ts` and pointing `inventory-returns` at `/admin/inventory/requests?tab=returns`). `flutter drive … --dart-define=DOCS_SCREENSHOTS=true` on AVD `inbils_pixel7` → 22 PNG in `images/technician/`, 17 in `images/customer/`, no debug banner (mobile `3470a40`). Template got `img { max-height: 11cm }` so phone shots fit with their step text. `php -d memory_limit=1G artisan inbils:docs:pdf` → web 166 pages, teknisi 30, pelanggan 26.

## Comments

Code landed (DocsPipe, 2026-10-13), nothing committed:

- `app/Console/Commands/DocsPdfCommand.php`, `resources/views/pdf/user-guide.blade.php`, `tests/Feature/DocsPdfCommandTest.php`. `--dir=` overrides the guide folder (tests). TableExtension added (guides use tables); raw HTML stripped; images inlined as data URIs, remote/outside-dir/missing image fails the guide; page numbers drawn on the canvas (no `isPhpEnabled`).
- `scripts/docs-screenshots.{mjs,json}` + `docs-screenshots.config.ts` + `docs-screenshots.spec.ts` (launcher = `e2e-setup.mjs` then `e2e-canonical.mjs --config scripts/docs-screenshots.config.ts`), npm script `docs:screenshots`. Output `docs/user-guide/images/web/<slug>.png`. 51 screens incl. login.
- Mobile: `{technician,customer}/integration_test/docs_screenshots_test.dart` (walks `topLevelScreens`, slug of the title) + `test_driver/integration_test.dart` (writes to `../../inbils/docs/user-guide/images/<technician|customer>/`, env `DOCS_IMAGES_DIR`). Run with `flutter drive`, NOT `flutter test` (only the driver receives device screenshots).
- `.gitignore`: `!docs/user-guide/`. `docs/user-guide/README.md` documents all commands.

Evidence: `sail ... artisan test --compact tests/Feature/DocsPdfCommandTest.php` → 7 passed (17 assertions). Manual fixture run `artisan inbils:docs:pdf --only=web --dir=/tmp/g` → `%PDF-1.7`, 4 pages A4 (cover, TOC, 2 chapters), 1280×800 PNG fits the page, table rendered, footer "Halaman N dari 4" (checked with pdftoppm). `playwright test --config scripts/docs-screenshots.config.ts --list` → 1 test found. `flutter analyze` on both new test + driver files → no issues. Not run: screenshot scripts, `flutter drive`, real three PDFs.
