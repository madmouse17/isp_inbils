# UI: Profile Security card, challenge page, Users page 2FA badge

Status: done
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

- [x] A user can enrol, confirm, see codes once, reload and no longer see secret or codes. (`tests/e2e/two-factor.spec.ts`, canonical run green.)
- [x] Challenge page works with TOTP and with a recovery code, and displays the throttle message. The throttle text arrives in the same `code` error the page renders (PHPUnit asserts it); E2E cannot trip the throttle because `.env.e2e` uses the array cache.
- [x] Reset action appears only for permitted actors and never for the actor's own row. Server flag `can.reset_two_factor` is PHPUnit-covered; the browser test (`two-factor.spec.ts`, reset test) passes with the seeded permission: own page has no button, the colleague's page has it, and the Users index 2FA column shows On then Off.
- [x] `npm run typecheck`, `npm run build`, scoped ESLint pass. Labelled inputs, visible focus and no horizontal overflow at 320px and 1280px in light and dark were checked on screenshots; no new colour pairing (existing Badge/Button tokens), so the contrast tool was not needed.
- [x] No secret or recovery code is stored in localStorage, query strings, or Inertia history state (secrets travel as Inertia flash data; E2E asserts storage, `history.state` and the URL).
