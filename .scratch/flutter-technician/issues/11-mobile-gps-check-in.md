# MOBILE + BACKEND: GPS check-in at the job site

Status: ready-for-agent
Role: mobile (app) + backend (API field)
Blocked by: 04 (start/submit), mobile-api 05
Card: none (owner added 2026-10-13)

## Scope

- On Start of an SPK or ticket, the technician app asks for foreground location permission (`geolocator`, while-in-use only), reads one fix (timeout 15 s, accuracy reported) and sends `{lat, lng, accuracy_m, captured_at}` with the start action.
- API (mobile-api 05/04): start endpoints accept the optional check-in, store it on the work order/ticket activity (audit log properties, no new PII table), and compute the distance to the job location when the customer address or location has coordinates. No hard block on distance in v1: the web SPK/ticket page shows "check-in 120 m from site" or "no location".
- Permission denied or no fix: start still works, check-in recorded as `unavailable` with the reason.
- No background location, no tracking between jobs.

## Acceptance criteria

- [x] (API side) Start with permission granted stores lat/lng/accuracy and the computed distance; denied stores `unavailable`. (Mobile capture still open.)
- [ ] Android manifest declares only ACCESS_FINE_LOCATION/ACCESS_COARSE_LOCATION (no background location).
- [x] (API side) API feature test for the stored check-in and the distance. (Widget test for the permission-denied path still open.)
- [ ] Web SPK/ticket show displays the check-in summary.

## Comments

- API done: `check_in` on `POST technician/work-orders/{wo}/start` and `technician/tickets/{id}/start`, stored as activity log (`check_in`) properties, no new table. Technician work order and ticket resources expose `check_in` {status: recorded|unavailable|none, distance_m, accuracy_m, captured_at, reason}. Web props: SPK `checkInSummary` (string), ticket `check_in` (string) already rendered. Open: mobile capture, manifest, widget test.
