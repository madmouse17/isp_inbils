# UI: platform panel pages

Status: ready-for-agent
Role: ui
Blocked by: 02, 03, 05 (props from the design)
Spec: `../spec.md`

## Scope

`resources/js/Layouts/PlatformLayout.tsx`, `Pages/Platform/**`: login + 2FA, dashboard (tenants list with metrics), company detail with actions and double confirmation (R4), new company form, role & permission editor (moved from tenants), audit log, exports. Indonesian, `Components/ui` only, dark mode, keyboard. E2E `tests/e2e/platform.spec.ts` (login, create company, suspend, export request).

## Acceptance criteria

- [ ] `tsc`, ESLint, selfchecks clean; E2E spec type-checks.

## Comments
