# BACKEND: customer API completeness (speed, SLA, billing detail, PPPoE username, address map, ticket handler)

Status: done
Role: backend
Blocked by: none
Spec: `../spec.md` (owner 2026-10-16)

## Scope

`CustomerSubscriptionResource` `package` gains `download_mbps`, `upload_mbps` (from the package's bandwidth profile), `speed_label` (e.g. "Hingga 50 Mbps", from the speed profile max or bandwidth download) and keeps `name`/`category`; eager-load, no N+1; OpenAPI regenerated + contract test; tests for present/absent profiles.

Part 2 (customer info audit, owner 2026-10-16 "cek juga pada customer apakah informasinya sudah lengkap"):
- Subscription: `sla_tier {name, response_time_hours, resolution_time_hours}`; `pppoe_username` (from `NetworkAccount`, never the password); `installation_address {address, city, label, lat, lng}` (coordinates of the customer's own address only); `suspended_at`.
- Invoice: `billing_period {start, end}`; items keep `tax_rate`, `discount_amount`; payments add `reference` as a customer-safe value (our payment code or the gateway order id already visible to the customer on Midtrans; never raw gateway payloads) and `method_label`.
- Ticket: `priority`, `created_at`, `resolved_at`, `response_deadline`, `sla_deadline`, `first_response_at`, `handler_first_name` (first name only, no phone).
- Payment flow (owner 2026-10-16 "audit pembayaran midtrans"): invoice detail adds `payment_attempt {status: pending|settlement|expire|failed|null, expires_at, redirect_url?}` for the latest attempt of this customer; `GET /customer/invoices/{id}/receipt` returns a PDF (dompdf, existing invoice template) only for paid invoices of the owning customer (404 otherwise); `payments[].reference` as above.
- Keep hidden: static/assigned IP, POP, ONT, NetworkAccount password, internal notes, cancel fields, technician phone.
- OpenAPI + matrix + resource tests; `ApiExposureTest`-style assertion that forbidden keys never appear.

## Acceptance criteria

- [x] Focused PHPUnit (Api/V1/Customer, OpenApiContract, matrix) green; Pint.

## Comments

- Added package speed/profile fields, SLA, PPPoE username, mapped installation address, and suspension timestamp with eager loads.
- Added invoice billing period, safe payment reference/labels, latest payment attempt, and paid-invoice PDF receipt.
- Added ticket SLA/response timestamps and handler first name only.
- Added present/absent, exposure, tenancy, throttle, OpenAPI, and N+1 coverage. Focused customer/matrix: 108 passed (3236 assertions). OpenAPI: 2 passed (125 assertions). Pint: clean.
