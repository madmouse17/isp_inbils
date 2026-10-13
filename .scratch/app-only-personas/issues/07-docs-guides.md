# DOCS: the three Indonesian guides

Status: done
Role: docs
Blocked by: 06 (images available)
Spec: `../spec.md`

## Scope (owned files)

- `docs/user-guide/web.md`, `docs/user-guide/teknisi.md`, `docs/user-guide/pelanggan.md` per spec Q5/Q7/R6: Indonesian, per-menu chapters (Dasbor, Operasional: Penugasan/Persetujuan/SPK/Tiket/Evaluasi; Pelanggan & Layanan; Jaringan; Inventaris; Keuangan & Laporan incl. Penomoran; Administrasi), each with purpose, roles, numbered steps referencing `images/web/<slug>.png`, common mistakes; "Alur kerja" chapter; app guides per screen (login, 2FA challenge, My Work, SPK, evidence, material, inventory request, tickets, attachments, notifications, account; customer: home, invoices, pay, payments, subscriptions, tickets, account). Facts come from the code and the business docs (`docs/business/*.md`): never invent a feature; if a screen is unclear, read its page component.

## Acceptance criteria

- [x] Every image referenced exists; `php artisan inbils:docs:pdf` renders all three without warnings; PDFs committed.

## Comments

- 2026-10-14 (orchestrator): `web.md` (10 H1, 60 H2, 54 images, all slugs used once), `teknisi.md` (35 headings, 22 images), `pelanggan.md` (35 headings, 17 images). All three render with no warning. The web guide states current UI defects as limitations (listed in `.scratch/HANDOFF-2026-10-14.md`); remove each caveat when its defect is fixed. Fix found while writing: `MaterialReturnController::receiveReject` read `rejection_reason` instead of the validated `reason` (500 on "Tolak Penyerahan"); fixed with an HTTP test.
