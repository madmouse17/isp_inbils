# BACKEND: private disk, authorized media route, migration command

Status: done
Role: backend
Blocked by: none (starts only after owner approves `../spec.md` Q1-Q3)
Card: none (new lane; no Hermes card)

## Scope

- `UploadsMedia::storeMedia`, `DocumentService` and the demo seeder write to a private disk (Q1); `CompanyLogoService` unchanged.
- Route `GET admin/media/{media}` (name `admin.media.show`): resolves `$media->model`, aborts 404 when the owner is missing or outside the effective company, runs `Gate::authorize('view', $owner)`, streams the file (inline for raster images, attachment otherwise: PDF downloads, because the CSP sandbox that would contain a PDF viewer stops Chrome from rendering it). Customer users reach only media of their own tickets through `TicketPolicy::view`.
- `MediaResource` and `DocumentService::list` return `route('admin.media.show', $media, false)` instead of `/storage/...`.
- Artisan `inbils:media:privatize {--dry-run}`: moves existing non-logo media from the `public` disk to the configured private disk, idempotent, reports counts. Hardened after the security review (2026-10-12): selects only rows on `public`; refuses to run (exit 1, nothing touched) when `MEDIA_DISK` is `public`, public-visibility, unconfigured or the same storage as `public` (probe file, real runs only); lists every `Missing <id>: public:<path>` row and exits 1 when any media stays behind; refuses a media whose files are not in a directory named after its id.

Non-goals: logo, image conversions/thumbnails, CDN.

## Acceptance criteria

- [x] New uploads (ticket attachment, SPK evidence, document) land on the private disk; nothing new appears under `storage/app/public` except the logo.
- [x] Guest request to the media route redirects to login; a user of another company gets 404; a customer user gets 403 for another customer's ticket attachment and 200 for their own.
- [x] `/storage/{id}/{file}` no longer serves a migrated file.
- [x] `--dry-run` moves nothing; a real run is idempotent (second run reports 0).
- [x] Pint clean; feature tests for each owner type.

Delivered: `config/media-library.php` (`disk_name` defaults to `local`; the logo keeps its explicit `public`), `app/Http/Controllers/Admin/MediaController.php` + `routes/admin.php` (`admin.media.show`), `app/Console/Commands/PrivatizeMediaCommand.php`, `MediaResource`, `DocumentService`, `UploadsMedia`, `database/seeders/Demo/SpkFlow.php`; tests `PrivateMediaAccessTest`, `PrivatizeMediaCommandTest`. Customer documents are readable by every holder of `customer.view` (`CustomerPolicy::view`), as the spec's `Gate::authorize('view', $owner)` implies.
