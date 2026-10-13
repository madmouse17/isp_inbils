# UI: Pengumuman & Promo page with phone-frame preview

Status: ready-for-agent
Role: ui
Blocked by: 01
Spec: `../spec.md`

## Scope (owned files)

`resources/js/Pages/Admin/Announcements/**` (tabs, lists with `EnumBadge` statuses, forms with `DateTimePicker`, targeting pickers (`SearchSelect` for locations and package categories), image upload with ratio hint and alt text, phone-frame preview component `Components/composite/PhonePreview.tsx` rendering the card and the carousel as the app will), sidebar item "Pengumuman & Promo" (navigation, fixture, `AdminMenuAuthorizationTest`, selfcheck), Indonesian copy, dark mode. E2E `tests/e2e/announcements.spec.ts` (create announcement + banner, publish, see status, API feed contains it for a matching customer via `support/api.ts`).

## Acceptance criteria

- [ ] `tsc`, ESLint, selfchecks clean; E2E spec type-checks.

## Comments
