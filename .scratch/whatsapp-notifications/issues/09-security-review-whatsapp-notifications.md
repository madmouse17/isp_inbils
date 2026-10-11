# SECURITY REVIEW: WhatsApp notifications release gate

Status: done
Role: security-reviewer
Blocked by: 08
Card: none (new lane; no Hermes card)

## Scope

Read-only `security-reviewer` pass (repo rule 10: user data, external channel, auth-adjacent access-link notice). Review tickets 01-08 against `../spec.md`:

- Webhook: HMAC over the raw body before any parsing/DB access, constant-time compare, verify-token handshake, no oracle in responses, throttle scope, no CSRF/session exposure, STOP handling cannot be abused to opt out arbitrary customers without a valid Meta signature.
- Secrets: token, app secret and verify token only from env; never logged, serialised in jobs, exposed in props (`configured` is boolean), exceptions or test output; real `.env*` untouched.
- PII: no full phone, rendered text or parameters in `whatsapp_messages`, `jobs`/`failed_jobs`, logs, activity log; `recipient_hash` keyed (not a bare hash of a phone, which is brute-forceable); retention/prune works.
- Consent: messages impossible without `opt_in_at`, opt-out honoured immediately (including queued jobs that run after opt-out), consent writes authorised (`customer.update`) and audited.
- Tenant isolation: channel runs in the notification's company; webhook updates and opt-outs scoped per company; settings and message panel scoped to the current company.
- Access link: no URL or token over WhatsApp; e-mailed token not invalidated; masked e-mail only.
- Abuse/cost: dedupe keys, monthly cap, per-company rate limit, staff resend throttle.
- Message content: technician message carries no customer phone/address; customer message carries no technician phone.
- Dependency advisories: `composer audit`, `npm audit`, every severity (package, CVE, severity, affected vs fixed, update command); no new Composer package expected (Q2a).

## Acceptance criteria

- [x] Verdict APPROVED or BLOCKED with file:line findings and a fix per finding.
- [x] Advisory table present (or "none" with the command output).
- [x] Explicit statement whether production sending may start, given Meta Business verification, approved templates and host-only credentials.

## Comments

### Security gate, 2026-10-12

- Verdict: **APPROVED**. `security-reviewer` verified all 7 fixes in code.
- Privacy/tenant evidence: `DOCKER_CONTEXT=default ./vendor/bin/sail exec -T laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=2G artisan test --compact tests/Feature/WhatsApp` -> `Tests: 197 passed (2584 assertions)`; mutation storing the full phone in `recipient_hash` made 5 privacy tests fail (reverted). See ticket 08.
- Advisories (run 2026-10-11): `composer audit` none; `npm audit` none.
- Production sending: may start only after the owner prerequisites (Meta Business verification, WABA number, approved templates, host-only `WHATSAPP_*` credentials, public HTTPS webhook registered). Features default off per company.
