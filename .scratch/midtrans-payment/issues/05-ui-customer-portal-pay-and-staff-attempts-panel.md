# UI: customer portal invoice pages with Pay button, staff "Online payments" panel

Status: done
Role: ui
Blocked by: 03, 02
Card: none (new lane; no Hermes card)

## Scope

- Portal pages (React/Inertia, dedicated minimal layout, not the admin sidebar): invoice list (number, due date, total, balance, status badge), invoice detail (items, payments, status), Pay button (disabled with reason when not payable; on success follows the server's redirect URL), payment-return page showing the server-side state (pending / paid / failed / expired) with a refresh affordance.
- Forced password-change screen reuse (Q3) for `must_change_password`.
- `Pages/Admin/Billing/Invoices/Show.tsx`: read-only "Online payments" panel listing attempts (status, amount, Midtrans transaction id, payment type, created/settled time, exception reason), visible with `billing.view`; no action can mark an attempt paid.
- Reuse `Components/ui` + `composite`; Indonesian copy; dark mode; 320px layout; keyboard path; status shown as text + icon; double-click protection on Pay (disable while pending); no keys or tokens in the DOM (antislop-ui, antislop-human, antislop-layoutmobile).

Non-goals: backend contracts, snap.js embedding, reconciliation report page.

## Acceptance criteria

- [x] A customer can reach the portal after login, list/open own invoices, press Pay and land on the (mock) Midtrans redirect; returning shows the correct status.
- [x] Pay is disabled for paid/cancelled invoices and shows the reason; a (double) click does not open a second payment (ref guard in the page plus server-side reuse).
- [x] Staff panel shows attempts and an `exception` with its reason on a test invoice; absent for users without `billing.view` (they cannot open the page).
- [x] `npm run typecheck`, `npm run build` and scoped ESLint pass; E2E asserts no horizontal overflow at 320px in light and dark. Not audited visually: colour contrast of the existing tokens and keyboard focus order (DOM order equals visual order, no tabindex tricks).
