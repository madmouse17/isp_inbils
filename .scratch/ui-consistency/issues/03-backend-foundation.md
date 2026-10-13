# BACKEND: Indonesian locale, lang files, SearchOptions helper

Status: ready-for-agent
Role: backend
Blocked by: none
Spec: `../spec.md`

## Scope (owned files)

- `lang/id/{validation,auth,passwords,pagination}.php`, `lang/id.json` (R3), attribute names for common fields (`attributes` array: email, password → kata sandi, name → nama, …).
- `config/app.php` locale/fallback `id`; `.env.example` and `.env.docker.example` `APP_LOCALE=id`, `APP_FALLBACK_LOCALE=id` (never real `.env*`; deploy note in CHANGELOG).
- New `app/Support/SearchOptions.php` per R4 with a feature test (min chars, limit 20, escaping, company scope, selected value included).
- Breeze auth controllers' flash/status strings (`app/Http/Controllers/Auth/*`), `CompanySwitchController`, `SetupWizardController` messages, `app/Http/Requests/Auth/*`.
- Update PHPUnit tests that assert English framework validation or auth text (grep `The .* field`, `These credentials`, `Too many login attempts`, `passwords.`).

## Acceptance criteria

- [ ] Full PHPUnit green after the locale switch; `SearchOptions` test green; Pint clean.

## Comments
