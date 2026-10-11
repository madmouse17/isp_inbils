# UI: Profile Security card, challenge page, Users page 2FA badge

Status: needs-owner-approval
Role: ui
Blocked by: 02
Card: none (new lane; no Hermes card)

## Scope

- `Pages/Profile/Edit.tsx`: new Security card (partial component): state off / pending confirmation (QR as inline SVG + setup key text + code field) / on (regenerate codes, disable); recovery codes shown once with copy and download-as-text; password-confirm redirect handled with the existing confirm page.
- `Pages/Auth/TwoFactorChallenge.tsx`: code field (`inputmode="numeric"`, `autocomplete="one-time-code"`, autofocus) with a "use a recovery code" toggle; shows throttle errors.
- Admin Users index/show: 2FA badge and a reset action (confirm dialog) shown only when the server says the actor `can.reset_two_factor`.
- Reuse `Components/ui` + `composite`; dark mode; 320px; keyboard-only path; status is text + icon, not colour alone (antislop-human, antislop-layoutmobile).

Non-goals: backend contracts, enforcement banners, passkey UI.

## Acceptance criteria

- [ ] A user can enrol, confirm, see codes once, reload and no longer see secret or codes.
- [ ] Challenge page works with TOTP and with a recovery code, and displays the throttle message.
- [ ] Reset action appears only for permitted actors and never for the actor's own row.
- [ ] `npm run typecheck`, `npm run build`, scoped ESLint pass; axe-style checks: labelled inputs, visible focus, contrast ≥ AA in both themes (use the contrast tool for any new colour pairing).
- [ ] No secret or recovery code is stored in localStorage, query strings, or Inertia history state.
