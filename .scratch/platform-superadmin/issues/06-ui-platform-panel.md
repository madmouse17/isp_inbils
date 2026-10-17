# UI: platform panel pages

Status: done
Role: ui
Blocked by: 02, 03, 05 (props from the design)
Spec: `../spec.md`

## Scope

`resources/js/Layouts/PlatformLayout.tsx`, `Pages/Platform/**`: login + 2FA, dashboard (tenants list with metrics), company detail with actions and double confirmation (R4), new company form, role & permission editor (moved from tenants), audit log, exports. Indonesian, `Components/ui` only, dark mode, keyboard. E2E `tests/e2e/platform.spec.ts` (login, create company, suspend, export request).

## Acceptance criteria

- [x] `tsc`, ESLint, selfchecks clean; E2E spec type-checks.

## Comments

2026-10-17, PlatformUi: Part 1 complete. Built the platform shell, auth and mandatory 2FA pages, tenant dashboard/create/detail, lifecycle confirmations, subsidiary creation, read-only tenant people/activity/export sections, and global role editor. Audit/Exports index pages, support actions, export requests/downloads, and impersonation controls remain for Part 2 when tickets 04/05 publish their routes and props. Added E2E-only platform operators plus a suspendable tenant fixture and `tests/e2e/platform.spec.ts`; spec is type-check only in this part.

Design/backend differences followed:
- Controllers render `Platform/Auth/TwoFactorSetup`, not `TwoFactorEnrol`.
- `exports` detail rows currently have no `download_url`; the UI renders the real rows without dead download controls.
- Reactivate requires only a reason and no platform kata-sandi/code step-up, matching the actual route/request.

These PHPUnit assertions can now drop the second `false` argument from `->component()`: `PlatformAuthenticationTest` (`Platform/Auth/Login`, `TwoFactorChallenge`, `TwoFactorSetup`, `ConfirmPassword`), `PlatformRoleManagementTest` (`Platform/Roles/Index|Create|Edit`), `TenantManagementTest` (`Platform/Dashboard`), `TenantProvisioningTest` (`Platform/Companies/Create`), and `TenantDetailTest` (`Platform/Companies/Show`). `TenantExportTest` must keep it until Part 2 adds `Platform/Exports/Index`.

2026-10-17, PlatformUi2: Part 2 complete.

Built:
- `Platform/Exports/Index`: status filter, company chip (`?company_id=`, cleared with one button), tenant link per row, `EnumBadge` status, size/rows/expiry, signed `download_url` link, `router.poll` (5 s, `mode: 'rest'`) only while a row is `queued` or `running`. Decision (b): requests live on the company page only, so the index has no tenant picker.
- `Platform/Audit/Index`: filters (search, company, operator, action as `SearchSelect`, `DateRangePicker`), `DataTable`, row `Modal` with `properties` and `batch_uuid`. The date filter counts UTC days (the controller does); the page says so.
- `Companies/Show`: per-user `Masuk sebagai {name}` button (`role=alertdialog`, `Alasan` + `Kata sandi operator`, `Mulai akses dukungan`, Inertia `router.post`) and an overflow menu (atur ulang 2FA, buka kunci masuk, kirim ulang undangan); `Minta ekspor` (company code + operator password); `Semua ekspor` and `Lihat semua audit` links; 30-day failed jobs and WhatsApp lists (`Lihat 30 hari terakhir`, paginated, loading/error/retry states) from `platform.companies.failed-jobs|failed-notifications`.
- `usePlatformAction` (confirmed POST guard + errors) turns the plain 429 of the invitation throttle into a toast through the Inertia `invalid` event instead of Inertia's raw HTML frame.
- Fixed the `ActivitySections` label lookup that never reached `AUDIT_EVENT` (`resolveStatus` always answers, so `.known` decides).
- Sidebar links `Ekspor` and `Audit platform`; `PLATFORM_AUDIT_ACTION` lists every code the backend writes.
- `tests/e2e/platform.spec.ts`: export request (first queued, second refused), audit page, impersonate start and stop from the company page (type-check only, not run). `tests/e2e/support/platform.ts` now paces operator TOTP codes (one step per login, waits when the next step is spent, last step kept in `tests/e2e/.runtime/platform-operator-totp-step`): the server rejects a code at or before the last used step, so two operator logins inside one 30 s step failed. This helper is shared with `impersonation.spec.ts`.
- Flipped `->component('X', false)` to `->component('X')` in `PlatformAuditTest`, `TenantExportTest`, `PlatformRoleManagementTest`, `TenantDetailTest`, `TenantManagementTest`, `TenantProvisioningTest`, `PlatformAuthenticationTest`.

Differences followed: Show `exports` rows carry no `download_url` (backend test pins it), so downloads live on the index only. Write mode needs nothing from the UI: `ImpersonationController::write` already answers `back(303)` and the banner handles the 423 step-up. `Show` is not polled: its props are eager, so a partial reload would still run the whole detail query.

Risks: the 30-day failed list scans `failed_jobs` per page request (backend `cursor()`); no render check or E2E run in this part.
