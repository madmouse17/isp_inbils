# Per-ISP integrations: Midtrans, WhatsApp, mail, RADIUS configured per company; FCM test from the apps

Status: ready-for-agent
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `tenant-integrations`
Source: owner 2026-10-17: "bisa tidak untuk seperti midtrans atau yang lain bisa terpisah dan ada config sendiri? … akan digunakan bukan hanya 1 perusahaan saja" → "ya, dan pada android kasih fitur untuk test FCM/notifikasi". Evidence: flow-audit F5-D10/F5-D18 (one merchant FHTS for every tenant), F12-D10 (platform mail sender), `.env.example` keys `MIDTRANS_*`, `WHATSAPP_*`, `MAIL_*`, `RADIUS_*`, `FIREBASE_CREDENTIALS`.

## Owner decisions (accepted)

1. Providers configured **per company**: `midtrans`, `whatsapp`, `mail`, `radius`. Stays platform-global: `firebase` (one Firebase project owned by the platform), storage, queue, cache.
2. Storage: table `company_integrations` (`company_id`, `provider`, `credentials` encrypted JSON (`encrypted:array` cast), `settings` JSON (non-secret: mode sandbox/production, from name, quiet rules already in `companies.settings` stay there), `is_enabled`, `verified_at`, `last_error`, `last_checked_at`, timestamps; unique `(company_id, provider)`). Secrets are write-only: the API/Inertia never return them, the UI shows "tersimpan (••••1234)" with the last 4 characters only.
3. Resolution: one `IntegrationConfig` service (`forCompany($companyId, $provider)`, `current($provider)`), cached per request. Fallback to `.env` ONLY for `mail` (platform sender). Midtrans/WhatsApp/RADIUS without a company row = disabled for that company: UI shows "Belum dikonfigurasi" with a link to Pengaturan → Integrasi; customer app shows the existing `payment.enabled=false` reason.
4. Settings page Pengaturan → Integrasi (admin only, permission `company.integrations.manage`, 2FA step-up for saving secrets): one card per provider with fields, Simpan, "Uji koneksi" (Midtrans: `GET /v2/ping`-equivalent via a status lookup of a dummy order and key validation; WhatsApp: fetch the phone number profile; mail: send a test mail to the admin; RADIUS: `SELECT 1` + check the five tables exist), result stored in `verified_at`/`last_error`, activity-logged (without secrets).
5. Webhooks per tenant: Midtrans notification URL `/webhooks/midtrans/{company_public_id}` verified with that company's server key (the existing global route stays for the migration period and resolves the company from the attempt's order id); Snap requests keep sending `X-Override-Notification` with the per-company URL so ISPs need not touch the Midtrans dashboard. WhatsApp webhook routes by `phone_number_id` → company; the verify token is per company.
6. Platform superadmin: tenant page shows per provider `tidak diisi / terisi / terverifikasi / error` + last check time; never the values.
7. One-shot migration command `inbils:integrations:import-env --company=<code>` copies the current `.env` values into that company's rows (used once on dev for AUDIT-ISP/DEMO and on 8080); after that the app ignores `MIDTRANS_*`, `WHATSAPP_*`, `RADIUS_*` for runtime (kept only as the import source), `.env.example` documents the change.
8. **Apps: FCM/notification test.** Customer and technician apps get, in Pengaturan → Notifikasi, a "Kirim notifikasi uji" button: `POST /api/v1/me/push-test` (throttled 3/min, idempotency key) sends a database+FCM notification "Notifikasi uji dari {company}" to the current device token only; the screen shows the three checks (izin notifikasi OS, token terdaftar di server, pesan diterima dalam 30 s with a live countdown and the heads-up) and a "Perbaiki" hint per failing check (open OS settings, re-register token, check the server's Firebase status). The web Pengaturan → Integrasi shows the platform Firebase status (credentials file present, last successful send) read-only.

## Non-goals

No per-ISP Firebase project / white-label app; no SMS gateway; no change to the WhatsApp quiet-hours/caps logic; no UI for storage.

## Tickets

01-design (architect) · then slices (backend core + per provider, web UI, platform view, mobile push-test, qa, docs/OpenAPI).
