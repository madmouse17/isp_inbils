# Private Media — serve uploaded documents and attachments through authorized routes

Status: needs-owner-approval
Ledger: none yet (found by the security review of the ticket/payment guards, 2026-10-11, finding F-01)
Source: `agent://TicketPaymentSecReview` F-01 (medium, high confidence), extended by grep to every media writer

> Nothing in this lane may start until the owner answers the decisions below. "setuju rekomendasi" approves the recommended option of every item.

## Problem

Every uploaded file is stored on the `public` disk and linked as `/storage/{media_id}/{file_name}`. Downloads never pass a policy, so anyone who knows or guesses the path (sequential media id + original file name) can fetch the file without logging in, across companies.

Writers today:
- `app/Http/Controllers/Concerns/UploadsMedia.php:16` — ticket attachments (`Modules/Ticketing/app/Http/Controllers/TicketController.php`), SPK evidence (`Modules/SPK/app/Http/Controllers/WorkOrderController.php`).
- `app/Services/Core/DocumentService.php:48` — customer/employee/vehicle documents (contracts, identity documents); `:64` exposes `$media->getUrl()`.
- `app/Services/Core/CompanyLogoService.php:19,27` — company logo (public by nature).
- `database/seeders/Demo/SpkFlow.php:71` — demo evidence.
- Readers: `app/Http/Resources/MediaResource.php:16-17` (`file_path`, `url`).

## Owner decisions

1. **Q1 — Which collections become private?**
   (a) Everything except the company logo. (b) Only customer documents. **Recommend (a).** Ticket attachments and SPK evidence photos show customer premises and equipment; documents hold identity data. The logo stays public because the login page and PDFs need it without a session.
2. **Q2 — How are files served?**
   (a) Private `local` disk + one authorized route per owner type (`GET admin/media/{media}` resolving the owning model and running `Gate::authorize('view', $owner)`, plus company check), streamed with `Content-Disposition`. (b) Signed temporary URLs (`temporaryUrl`, short TTL) on the private disk. **Recommend (a)**: one place to authorize, no link that keeps working after access is revoked. Tradeoff: every image preview costs a PHP request.
3. **Q3 — Existing files.** (a) Artisan command that moves existing media to the private disk (`disk`/`conversions_disk` update + file move), idempotent, dry-run first. (b) Leave old files public, new uploads private. **Recommend (a)**; (b) leaves the leak open for all current data.

## Tickets

- `issues/01-backend-private-media.md`
- `issues/02-ui-media-links.md`
- `issues/03-tester-media-access-matrix.md`
