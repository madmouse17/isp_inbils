# UI: media links and previews use the authorized route

Status: needs-owner-approval
Role: frontend
Blocked by: 01-backend-private-media
Card: none (new lane; no Hermes card)

## Scope

- Every page that renders `media.url` / `file_path` (ticket attachments, SPK evidence gallery, customer/employee/vehicle documents) keeps working with the new route URL: previews, downloads, open-in-new-tab.
- No hard-coded `/storage/` paths remain in `resources/js` except the company logo.

## Acceptance criteria

- [ ] `grep -rn "/storage/" resources/js` returns only logo usage.
- [ ] Image previews and downloads work on Tickets/Show, SPK/Show and document panels, light and dark, at 320px and 1280px.
- [ ] typecheck, eslint, prettier, build clean.
