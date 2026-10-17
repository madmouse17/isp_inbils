# UI: impersonation banner, read-only feedback, tenant audit entries, role editor removal for tenants

Status: done
Role: ui
Blocked by: 04
Spec: `../spec.md`

## Scope

Banner in `AdminLayout` (and `GuestLayout` pages a tenant user can see), countdown, mode, Keluar; read-only 403 toast copy; tenant Audit Log shows "Akses dukungan" entries; tenant Peran pages become read-only (assign roles to users still allowed in Pengguna). E2E `tests/e2e/impersonation.spec.ts`.

## Acceptance criteria

- [x] `tsc`, ESLint clean; E2E spec type-checks.

## Comments

2026-10-17, TenantBannerUi: `Components/composite/ImpersonationBanner.tsx` (+ `impersonationCountdown.ts` and its `.selfcheck.mjs`) renders from the shared `impersonation` prop in `AdminLayout` (banner above the sidebar/topbar row, Sidebar `lg:h-full`) and `GuestLayout`. Text: "Anda masuk sebagai {user} ({company}) · superadmin {admin} · mode Baca|Ubah · sisa mm:ss", live 1 s countdown (`role=timer`), reload at 0 so the server ends the session. "Mode ubah" opens a dialog that asks for the company code and axios-posts `write_url` as JSON (422 shows the server message under the field, 423 = operator step-up needed so it navigates to `confirm_url`); in write mode the button becomes "Mode baca" (posts `read_url`); "Keluar" posts `stop_url`. Read-only denials arrive as Inertia 303 + flash `error`, shown by the existing `useToast`. Prop contract used (added by PlatformImpersonation2): `{admin_name,user_name,company_name,company_code,mode,expires_at,reason,write_url,read_url,stop_url,confirm_url}`.

Tenant `AuditLog.tsx`: support rows (log `support`, causer already masked "Tim dukungan Inbils" by the query) show module badge "Akses dukungan" and text "Akses dukungan oleh tim Inbils: {aksi}" in table, mobile card, detail and search.

Tenant Peran pages: not touched here (no tenant role editor in this ticket's file ownership; role definitions are platform-managed).

`tests/e2e/impersonation.spec.ts` uses the existing E2ePlatformSeeder tenant admin; it depends on PlatformUi2's Platform/Companies users UI labels (`Masuk sebagai E2E Suspend Admin`, dialog `Alasan`/`Kata sandi operator`, `Mulai akses dukungan`). Not run (no E2E run in this assignment).

Evidence: `tsc --noEmit` exit 0; `eslint` on banner, countdown, both layouts, AuditLog.tsx exit 0; prettier --check clean; `node impersonationCountdown.selfcheck.mjs` -> ok; spec type-checked standalone with `tsc --strict` exit 0 (e2e specs are outside the project tsconfig and ESLint project service). `npm run build` and render check not run.

2026-10-17, TenantBannerUi follow-up: PlatformImpersonation2 confirmed `confirm_url` carries `?return_to=<current tenant path+query>`, so after the 423 step-up the server answers with a full load of the same tenant page. The banner already navigates with `window.location.assign(impersonation.confirm_url)`, so no change was needed. This replaces the earlier note that the operator lands on the platform page after step-up. The operator still clicks "Mode ubah" again after returning.
