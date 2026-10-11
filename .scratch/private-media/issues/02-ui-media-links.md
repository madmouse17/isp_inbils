# UI: media links and previews use the authorized route

Status: done
Role: frontend
Blocked by: 01-backend-private-media
Card: none (new lane; no Hermes card)

## Scope

- Every page that renders `media.url` / `file_path` (ticket attachments, SPK evidence gallery, customer/employee/vehicle documents) keeps working with the new route URL: previews, downloads, open-in-new-tab.
- No hard-coded `/storage/` paths remain in `resources/js` except the company logo.

## Acceptance criteria

- [x] `grep -rn "/storage/" resources/js` returns only logo usage (it returns nothing: the logo URL comes from the backend props).
- [x] Image previews and downloads work on Tickets/Show and SPK/Show, light and dark, at 320px and 1280px (`tests/e2e/private-media.spec.ts`, `tests/e2e/spk-lifecycle.spec.ts`). There is no customer/employee/vehicle document panel in `resources/js` yet (document endpoints are upload/delete only), so none to update.
- [x] typecheck, eslint, prettier, build clean on every touched file (`MediaPreview`, Tickets/Show, SPK/Show, types).

Delivered: new composite `MediaPreview` (thumbnail for images, Open in a new tab, Download) used by Tickets/Show attachments and SPK/Show evidence; `url`, `mime_type` and `original_name` added to the media types.
