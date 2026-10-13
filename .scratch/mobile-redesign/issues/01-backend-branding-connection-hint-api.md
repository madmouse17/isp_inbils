# BACKEND: company branding fields, connection status API, category hint

Status: ready-for-agent
Role: backend
Blocked by: sla-targets merged
Spec: `../spec.md`

## Scope (owned files)

- Migration on `companies`: `brand_color` char(7) nullable, `whatsapp` varchar(50) nullable, `support_hours` varchar(120) nullable; `Company` fillable; `UpdateCompanyProfileRequest` validation (`regex:/^#[0-9A-Fa-f]{6}$/`, phone pattern as `phone`, max lengths, Indonesian messages/attributes).
- Migration on `ticket_categories`: `customer_hint` text nullable (≤ 500 validated) in `UpdateTicketCategoryRequest`; exposed in the customer ticket-category API resource.
- Mobile API `me.company`: `logo_url` (signed or public URL as the web uses), `brand_color`, `phone`, `whatsapp`, `email`, `website`, `support_hours` (allow-list resource).
- `RadiusDirectory::lastSeen(string $username): ?string` (MAX(`acctstoptime`)) and `GET /api/v1/customer/subscriptions/{subscription}/connection` → `{ data: { status: 'online'|'offline', since: ?iso, last_seen_at: ?iso } }`, `data: null` when RADIUS is disabled or the subscription has no PPPoE username; ownership enforced (other customer/company → 404); throttled; never NAS IP, framed IP, session ids.
- Customer subscription resource: `suspension_reason` ∈ {`overdue`, `manual`, null} (from how the subscription was suspended: `OverdueSuspensionService` path vs a manual status change; find where the source is recorded, add a nullable column `suspension_reason` on `service_subscriptions` set by both paths if nothing records it today, backfill null). The app shows the isolir hero and the auto-reactivation promise only for `overdue` (ticket 04 `heroStatusOf`).
- Customer invoice resource: `subscription_id` (nullable) when the invoice belongs to one subscription, so each hero shows its own bill.
- OpenAPI regenerated + contract test. Tests per spec (API part).

## Acceptance criteria

- [ ] Focused PHPUnit green; Pint; OpenAPI contract test green.

## Comments
