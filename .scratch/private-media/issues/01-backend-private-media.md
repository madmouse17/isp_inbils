# BACKEND: private disk, authorized media route, migration command

Status: needs-owner-approval
Role: backend
Blocked by: none (starts only after owner approves `../spec.md` Q1-Q3)
Card: none (new lane; no Hermes card)

## Scope

- `UploadsMedia::storeMedia`, `DocumentService` and the demo seeder write to a private disk (Q1); `CompanyLogoService` unchanged.
- Route `GET admin/media/{media}` (name `admin.media.show`): resolves `$media->model`, aborts 404 when the owner is missing or outside the effective company, runs `Gate::authorize('view', $owner)`, streams the file (inline for images/PDF, attachment otherwise). Customer users reach only media of their own tickets through `TicketPolicy::view`.
- `MediaResource` and `DocumentService::list` return `route('admin.media.show', $media, false)` instead of `/storage/...`.
- Artisan `inbils:media:privatize {--dry-run}`: moves existing non-logo media to the private disk, idempotent, reports counts.

Non-goals: logo, image conversions/thumbnails, CDN.

## Acceptance criteria

- [ ] New uploads (ticket attachment, SPK evidence, document) land on the private disk; nothing new appears under `storage/app/public` except the logo.
- [ ] Guest request to the media route redirects to login; a user of another company gets 404; a customer user gets 403 for another customer's ticket attachment and 200 for their own.
- [ ] `/storage/{id}/{file}` no longer serves a migrated file.
- [ ] `--dry-run` moves nothing; a real run is idempotent (second run reports 0).
- [ ] Pint clean; feature tests for each owner type.
