# BACKEND: WhatsApp notice for the customer access link (no link over WhatsApp)

Status: ready-for-agent
Role: backend
Blocked by: 01
Card: none (new lane; no Hermes card)

## Scope

Spec Q13a.

- `CustomerAccountCreated` (`app/Notifications/CustomerAccountCreated.php`) gains constructor `public readonly int $companyId`; `via()` (`:16-19`) returns `['mail', WhatsAppChannel::class]` when the notifiable user has a linked customer, else `['mail']`; the channel's consent gate decides the rest.
- `toWhatsApp`: template from `config('whatsapp')` (`access_link_notice`), parameters: company name, masked e-mail (first character + `***@` + domain). **No URL, no token.** `toMail` and its send-time token minting (`:21-27`) stay unchanged — the WhatsApp path never calls the password broker.
- `viaQueues` maps the WhatsApp channel to `whatsapp`; not batch (sent immediately); dedupe `access_link_notice:{user}:{Y-m-d-H}`.
- `CustomerService::sendAccessLink` (`app/Services/Core/CustomerService.php:53-56`) passes `(int) $user->company_id` — the only construction site; its callers stay as they are: creation (`:35-37`), staff resend (`app/Http/Controllers/Admin/CustomerAccessLinkController.php:28`), portal (`Modules/Billing/app/Http/Controllers/Portal/PasswordController.php:34`), legacy rotation (`Modules/Billing/app/Console/RotateLegacyCustomerPasswords.php:49`).

Non-goals: sending the reset link over WhatsApp, phone-based password reset, changing flash messages.

## Acceptance criteria

- [ ] Consented customer created → one mail and one WhatsApp notice; the Graph request parameters contain no `http`, no token and no full e-mail.
- [ ] After the WhatsApp notice job runs, the token from the mail still resets the password (one `password_reset_tokens` row, unchanged).
- [ ] Customer without consent → mail only, WhatsApp row `skipped: no_consent`.
- [ ] Three resends within an hour (staff route throttle `routes/admin.php:77` is 3/min) → three mails and one WhatsApp notice.
- [ ] Existing `tests/Feature/CustomerAccountCreationTest.php` cases stay green with the new constructor; Pint clean.
