# UI (web): brand colour, WhatsApp, support hours on Company page; customer hint on Kategori Tiket

Status: ready-for-agent
Role: ui
Blocked by: 01; ui-consistency 09 merged (same Company Settings page)
Spec: `../spec.md`

## Scope (owned files)

Company profile page: brand colour field (8 presets as swatches + hex input, live contrast check against white text using the existing contrast helper or a small pure function with a selfcheck), WhatsApp and support hours inputs; `Pages/Admin/Tickets/Categories/Index.tsx`: "Saran untuk pelanggan" textarea (≤ 500, counter). Components from `Components/ui` only, dark mode, Indonesian.

## Acceptance criteria

- [ ] `tsc`, ESLint, selfchecks clean; existing company/category E2E specs updated.

## Comments
