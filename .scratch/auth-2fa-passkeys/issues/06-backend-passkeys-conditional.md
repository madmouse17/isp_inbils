# BACKEND (conditional): passkeys / WebAuthn

Status: needs-owner-approval
Role: backend
Blocked by: 05
Card: none (new lane; no Hermes card)

> Conditional. Do not start unless the owner chooses `../spec.md` Q1 (b) or (c) after seeing the TOTP release. If chosen, Q2 flips to `laravel/fortify` hosting `laravel/passkeys` (pre-1.0, `v0.2.1` at time of writing) and this ticket is re-planned and re-approved first; the notes below are the starting contract.

## Scope (starting contract)

- Dependency decision (Fortify vs `laravel/passkeys` alone vs `web-auth/webauthn-lib`) with licence/maintenance/audit evidence.
- `passkeys` table like isp_ari's (`2026_07_26_231612_create_passkeys_table.php`: user_id FK cascade, name, unique credential_id, json credential, last_used_at).
- Registration and login ceremonies bound to the app host (`relying_party_id`, allowed origin from `APP_URL`); passkey as an additional factor or password replacement per owner decision; interaction with the TOTP challenge and recovery.
- Management UI (list, rename, revoke) in the Profile Security card (UI follow-up ticket).

## Acceptance criteria

- [ ] Owner decision on factor vs replacement recorded in the spec.
- [ ] Registration, login and revoke work on a real browser against `inbils_test` (Playwright virtual authenticator).
- [ ] Credential data never appears in props/logs; `security-reviewer` APPROVED; `composer audit` pasted.
