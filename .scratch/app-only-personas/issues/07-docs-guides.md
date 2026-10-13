# DOCS: the three Indonesian guides

Status: in-progress
Role: docs
Blocked by: 06 (images available)
Spec: `../spec.md`

## Scope (owned files)

- `docs/user-guide/web.md`, `docs/user-guide/teknisi.md`, `docs/user-guide/pelanggan.md` per spec Q5/Q7/R6: Indonesian, per-menu chapters (Dasbor, Operasional: Penugasan/Persetujuan/SPK/Tiket/Evaluasi; Pelanggan & Layanan; Jaringan; Inventaris; Keuangan & Laporan incl. Penomoran; Administrasi), each with purpose, roles, numbered steps referencing `images/web/<slug>.png`, common mistakes; "Alur kerja" chapter; app guides per screen (login, 2FA challenge, My Work, SPK, evidence, material, inventory request, tickets, attachments, notifications, account; customer: home, invoices, pay, payments, subscriptions, tickets, account). Facts come from the code and the business docs (`docs/business/*.md`): never invent a feature; if a screen is unclear, read its page component.

## Acceptance criteria

- [ ] Every image referenced exists; `php artisan inbils:docs:pdf` renders all three without warnings; PDFs committed.

## Comments
