# BACKEND: en_route_at, submitted_at, en-route endpoint, site fix

Status: ready-for-agent
Role: backend
Blocked by: ui-consistency merged (spec Order)
Spec: `../spec.md`

## Scope (owned files)

- Migration: `work_orders.en_route_at`, `work_orders.submitted_at` (nullable datetime).
- `Modules/SPK`: `SpkService::markEnRoute` + `submit` sets `submitted_at` once; technician API route + controller action + request (F1); `TechnicianWorkOrderResource` adds `steps` (F2 shape: `[{key, label, at}]`, `current`), `site` from the installation address first and `pop` separately (F4), `customer.phone_e164` for the WhatsApp link; `FieldCheckIn::site()` argument order fixed for subscription SPKs.
- Web SPK show props: `steps` for ticket 03; Dispatch query exposes `en_route_at`.
- Customer notification `TechnicianEnRouteNotification` (database channel only, F6).
- Tests per spec (backend items).

## Acceptance criteria

- [ ] Focused PHPUnit green; Pint; OpenAPI regenerated.

## Comments
