# BACKEND: customer WhatsApp consent, message panel props and company WhatsApp settings

Status: ready-for-agent
Role: backend
Blocked by: 01
Card: none (new lane; no Hermes card)

## Scope

Write surfaces for consent (spec Q5a) and company settings (spec Q6a, Q8a, Q12a); read props for the UI (ticket 07).

- Requests: `whatsapp_opt_in` `['sometimes', 'boolean']` in `StoreCustomerRequest` and `UpdateCustomerRequest`.
- NEW `app/Actions/SetCustomerWhatsAppConsentAction.php` (pattern: `AssignCustomerAcquirerAction`, called at `app/Actions/CreateCustomerOnboardingAction.php:27`): `execute(Customer $customer, bool $optIn, User $actor): void` — opt-in sets `whatsapp_opt_in_at = now()`, `whatsapp_opt_in_source = 'staff'`, `whatsapp_opt_in_by = actor`, clears `whatsapp_opt_out_at`; opt-out sets `whatsapp_opt_out_at = now()`; no-op when the state is unchanged; `forceFill` (columns are not fillable); audit log name `whatsapp` with ids only.
- `CreateCustomerOnboardingAction`: pull `whatsapp_opt_in` out of `$data` before `CustomerService::createWithUser` (same as `acquired_by_user_id` at `:23-24`) and call the consent action after the customer exists when it is true.
- `CustomerController::update` (`app/Http/Controllers/Admin/CustomerController.php:175-190`): inside the existing transaction, call the consent action only when the key is present.
- `CustomerResource`: `whatsapp` object `{opted_in: bool, opted_in_at: ?string, opted_out_at: ?string, reachable: bool}` (`reachable` = `PhoneNumber::normalizeIndonesianMobile(phone) !== null`).
- `CustomerController::show` (`:107-133`): prop `whatsappMessages` — latest 20 rows of this customer in the current company, columns `id, event, subject_type, subject_id, status, skip_reason, error_code, recipient_last4, created_at, delivered_at, read_at, failed_at`; one query.
- Company settings: explicit rules in `UpdateCompanySettingsRequest` (`app/Http/Requests/Admin/UpdateCompanySettingsRequest.php:18-19`) for `settings.whatsapp_enabled` boolean, `settings.whatsapp_events` array with each item `in:` the event keys from `config('whatsapp')`, `settings.whatsapp_quiet_start|end` `date_format:H:i`, `settings.whatsapp_monthly_cap` integer 0-100000; the wildcard stays for other keys. `CompanyController::editSettings` (`app/Http/Controllers/Admin/CompanyController.php:50-70`) adds defaults for those keys and a `whatsapp` prop `{configured: bool, usage: {sent, delivered, failed, billable}}` counted for the current calendar month in company timezone (one grouped query).
- Document consent rules (who may tick, STOP, what is recorded) in `docs/business/customer.md`.

Non-goals: TSX (07), webhook opt-out (03), portal self-service, `CustomerService.php` (owned by ticket 06).

## Contracts produced (consumed by 07)

```ts
// Admin/Customers/Show + Edit: customer.whatsapp
type CustomerWhatsApp = { opted_in: boolean; opted_in_at: string | null; opted_out_at: string | null; reachable: boolean };
// Admin/Customers/Show: whatsappMessages
type WhatsAppMessageRow = { id: number; event: string; subject_type: string | null; subject_id: number | null;
  status: 'queued' | 'sent' | 'delivered' | 'read' | 'failed' | 'skipped'; skip_reason: string | null;
  error_code: string | null; recipient_last4: string | null; created_at: string;
  delivered_at: string | null; read_at: string | null; failed_at: string | null };
// Admin/Company/Settings: settings.whatsapp_* keys + prop `whatsapp`
type CompanyWhatsApp = { configured: boolean; usage: { sent: number; delivered: number; failed: number; billable: number } };
// Form field on customer create/update: whatsapp_opt_in: boolean
```

## Acceptance criteria

- [ ] Creating a customer with `whatsapp_opt_in=true` stores `opt_in_at`, `source=staff`, `opt_in_by` = actor; without it, all consent columns stay null.
- [ ] Update untick → `opt_out_at` set; re-tick → new `opt_in_at`, `opt_out_at` null; unchanged value → no write, no audit row.
- [ ] A user without `customer.update` cannot change consent (403); consent columns cannot be written through any other field.
- [ ] Show props contain at most 20 rows, only this customer's and only the current company's (two-company test); no full phone in the WhatsApp props.
- [ ] Settings validation rejects unknown event keys, bad `H:i`, cap > 100000; `configured` is a boolean and no credential value appears in props.
- [ ] Usage counters match seeded rows for the current month only.
- [ ] Pint clean; Larastan clean on touched files; existing customer and settings tests stay green.
