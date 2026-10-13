# MOBILE: technician app on the new design system

Status: done
Role: mobile
Blocked by: 03
Spec: `../spec.md`

## Scope (owned files, repo `inbils-mobile/technician`)

Seeded ISP branding, Home focused on today (next visit with distance and navigate, counts per status), restyled work-order list/detail, check-in, materials, tickets, account with theme mode. Keep every existing flow and test green.

## Acceptance criteria

- [x] `flutter analyze` clean; `flutter test` green.
- [x] `flutter build apk --debug --flavor dev` builds.

## Comments

### MobileTechnician report, 2026-10-14

Repo `inbils-mobile`, `technician/` only, nothing committed, no new package, core untouched.

#### Done

- New first tab **Beranda** (`lib/features/home/home_screen.dart`, `next_visit.dart`): greeting + date, hero card with the next visit (in-progress SPK first, else earliest schedule; customer, address, Hari ini / Besok / "Terlambat, jadwal …" with the warning hero when late, distance when the phone already has location permission, **Navigasi** into Maps, **Buka SPK**), empty card when no SPK waits, then four tappable count tiles (Ditugaskan, Dikerjakan, Menunggu tindakan, Total). A tile opens Pekerjaan on that status (`work_filter.dart`; new chip "Menunggu tindakan" = `status=resolved`). Start route is `/home`.
- Pekerjaan (`work_screen.dart`): cards with tone icon and status chips; the old count row moved to Beranda.
- SPK detail: hero header (danger tone when rejected), customer and site in cards. Ticket detail: same. Material rows as cards. Akun: profile card, heading **Tampilan** with `ThemeModeSelector`, then `AccountMenu` in one scroll. Banners (offline copy, queued photos) use the neutral and info tones; action bar on `surfaceContainer`.
- Shared: `info_card.dart` (`InfoCard`, `ToneIcon`), `openExternalLink` in `contact_actions.dart`.

#### Verified
- `flutter analyze` (technician) → No issues found!
- `flutter test` (technician) → +238, all passed (216 before; 22 new in `test/home_test.dart`: visit present/absent, in progress first, late, ticket is not a visit, distance with/without position and without coordinates, Navigasi URL, counts and tile → filter, error retry, 200 % text on Beranda and Akun, Tampilan applies dark).
- `dart format --set-exit-if-changed lib test integration_test` → 0 changed.
- `flutter build apk --debug --flavor dev` → built `build/app/outputs/flutter-apk/app-dev-debug.apk`.

#### Changed tests (behaviour unchanged, landing screen moved)
- `work_test.dart`, `spk_harness.dart` (`openSpk` now goes to the Pekerjaan tab; new `openSpkHome`), `auth_account_test.dart` (login lands on Beranda). Request-count assertions in `work_test.dart` now expect Beranda's two reads and that the unfiltered and SPK lists are not read again.
- `integration_test/screens.dart`, `journeys_test.dart`, `offline_journey_test.dart` updated to open the Pekerjaan tab first; new `Beranda` entry in the screen list. Not run (needs the AVD, ticket 07).

#### Not verified / risks
- No device or AVD run; distance and the permission check were only exercised through an overridden provider, not the real location plugin.
- `my-work` cannot sort by schedule: the next visit is chosen among the first 10 SPKs the API returns (`// shortcut:` in `next_visit.dart`). A technician with more than 10 open SPKs may see a later one.
- Address and coordinates come from the SPK detail request, one extra read on Beranda.
- (Resolved in the follow-up below: the screens listed as not restyled and the ISP logo in the header.)

#### Needs decision or change outside my files
- none

### MobileTechnician follow-up, 2026-10-14

- **Header (V9/V10).** `lib/features/home/company_badge.dart`: `CompanyBadge` shows `me.company.logoUrl` with `Image` (`frameBuilder` + `errorBuilder`), and a tinted monogram (initials of the company name, `primaryContainer`) while loading, when absent or when it fails. Beranda's header now reads: badge, company name, date, then the greeting. The image comes from `companyLogoProvider` (`NetworkImage` by default; a test seam).
- **Restyled** like the rest (`InfoCard`, `ToneIcon`, `EmptyState`, `SectionHeader`): photo queue (rows as cards, empty state, thumbnail radius), material return declaration (line cards, rejection heading), new-request form (heading, empty state, item cards), product picker (rows as cards with a "sudah ditambahkan" check state), SPK material lines (cards, empty state). Behaviour and strings unchanged.
- **Tests** (`home_test.dart` +6): logo present (image replaces monogram, URL requested), absent (name and monogram, no image), failing (monogram, no exception), initials. Existing tests for the restyled screens pass unchanged.
- **Verified:** `dart format --set-exit-if-changed lib test integration_test` → 0 changed; `flutter analyze` → No issues found!; `flutter test` → +244 all passed; `flutter build apk --debug --flavor dev` → built `app-dev-debug.apk`.
- **Not verified:** no device run; the real logo download was not exercised (tests use `MemoryImage` and a failing provider). A logo that is not a raster format (SVG) falls back to the monogram.
