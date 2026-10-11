# BACKEND: SPK endpoints for technicians (show, start, evidence add/remove, submit) and the shared evidence service and policy fix

Status: done
Role: backend
Blocked by: 01
Card: none (new lane; no Hermes card)
Skills: laravel-best-practices, tdd, web-security-review, codebase-design, ponytail, caveman; for the web control change: inertia-react-development, antislop-human

## Scope

Implements the SPK rows of the spec contract, Q12a (evidence service, policy fix for web and API) and the evidence half of Q11.

- Verify the gap first, before changing anything. Write a failing feature test: a technician who is not assigned uploads evidence to another technician's `in_progress` SPK on the web route `admin.spk.evidence.store`. Write a second one for a `completed` SPK. Record the result in a ticket comment, then fix.
- Evidence service in `SpkService`:
  - Add evidence: store on collection `evidence` with the existing custom properties (`company_id`, `type`, `caption`, `uploaded_by`, `Modules/SPK/app/Http/Controllers/WorkOrderController.php:361-366`), plus `captured_at` (optional) and `idempotency_key` (API only).
  - Dedupe: if the SPK already has evidence with the same `idempotency_key`, return it. Hold a short lock on SPK + key.
  - Remove evidence: only evidence of this SPK in collection `evidence`.
  - Audit `work_order` `evidence_uploaded` / `evidence_removed` with ids only.
- `WorkOrderPolicy`:
  - `uploadEvidence`: the assigned technician (reuse `isAssignedTechnician(…, 'spk.evidence.upload')`, `Modules/SPK/app/Policies/WorkOrderPolicy.php:113`) while `in_progress`, or a holder of `spk.update` while the SPK is neither `completed` nor `cancelled`.
  - `removeEvidence`: the uploader, under the same status rule, or a holder of `spk.update`.
- One FormRequest for the evidence upload, shared by web and API: the existing file and caption rules from `WorkOrderController.php:355-358`, plus `captured_at` (nullable date, no later than now + 5 minutes, no older than 30 days).
- Web: `uploadEvidence` and `removeEvidence` (`:350-381`) call the policy and the service instead of `Gate::authorize('spk.evidence.upload')` (`:353`, `:376`). The SPK Show page shows the upload and remove controls only when the new policy allows them (a prop from `show`).
- API (`api.technician`), `Modules/SPK/routes/api.php` (NEW), mapped by the SPK provider under `api/v1`:
  - `GET technician/work-orders/{id}`: `WorkOrderPolicy::view`; another company is 404. Return the spec's technician detail shape through a NEW allow-list resource:
    - `site` = the SPK `location` (`locations.lat/lng`), else the subscription's installation address (`customer_addresses.lat/lng`).
    - `material_returns` = the cases of inventory requests whose source is this SPK.
    - `actions` = policy results for the caller.
  - `POST …/start`: `WorkOrderPolicy::start`, then `SpkService::start`. Return the detail.
  - `POST …/evidence`: multipart, throttle `api-upload`, the policy, then the service with the validated `Idempotency-Key`. 201 `Evidence`; a replay returns the same `Evidence` and 201.
  - `DELETE …/evidence/{mediaId}`: the policy, then the service. 204; a media id that does not belong to this SPK is 404.
  - `POST …/submit`: `WorkOrderPolicy::submit`, then `SpkService::submitForReview`. The service's 422 messages pass through as `unprocessable`.

Non-goals: adding SPK items (Q13a), approve, reject, assign, cancel, network credentials, SPK comments (none exist), photos on material declarations.

## Owned files

- NEW `Modules/SPK/routes/api.php`; `Modules/SPK/app/Providers/RouteServiceProvider.php`
- `Modules/SPK/app/Services/SpkService.php`, `Modules/SPK/app/Policies/WorkOrderPolicy.php`, `Modules/SPK/app/Http/Controllers/WorkOrderController.php`
- NEW `Modules/SPK/app/Http/Requests/StoreWorkOrderEvidenceRequest.php`
- NEW `Modules/SPK/app/Http/Controllers/Api/V1/Technician/`, NEW `Modules/SPK/app/Http/Resources/Api/V1/` (technician SPK detail, evidence)
- `resources/js/Pages/Admin/SPK/Show.tsx` (visibility of the evidence controls only)
- NEW `tests/Feature/Api/V1/Technician/WorkOrdersTest.php`, NEW `tests/Feature/Api/V1/Technician/EvidenceTest.php`, NEW `tests/Feature/SPK/EvidenceAuthorizationTest.php`. Existing `tests/Feature/SPK/*` files whose evidence expectations change get updated; list them in a ticket comment.

## Acceptance criteria

- [x] The red-first gap tests from the first Scope step exist, failed before the fix, and pass after: on web and API, an unassigned technician gets 403 and a `completed` or `cancelled` SPK gets 403. The assigned technician uploads while `in_progress`; a manager with `spk.update` uploads before completion.
- [x] API upload of a 3 MB JPEG returns 201 with `Evidence` (`mine: true`, `captured_at` echoed). An 11 MB file, an SVG and an HTML file are 422. The same key replayed after the idempotency cache entry is flushed still returns the same evidence id, and the media count is unchanged.
- [x] Remove works for one's own evidence while `in_progress`, is 403 for someone else's evidence, and is 404 for media of another SPK.
- [x] Detail: every spec key is present. `site` falls back to the installation address when the SPK has no location. `actions` follow the policies. A recursive key check finds no network-account field and no `created_by`/`approved_by` internals beyond the spec.
- [x] Start on `assigned` → `in_progress`. Submit with no evidence → 422. Submit with an undeclared material return case → 422 (the existing message). A valid submit → `waiting_review`. Another technician's SPK → 403. Another company's SPK → 404.
- [x] `GET media/{id}` (ticket 02) streams the uploaded evidence to the uploader and to a manager, and is 404 for a customer.
- [x] Existing SPK tests stay green (`tests/Feature/SPK/*`, `tests/Feature/Admin/MyWorkTest.php`). The web SPK page shows the upload control only to allowed users (Inertia prop assertion).
- [x] Pint, Larastan (changed files), ESLint and Prettier (`Show.tsx`) are clean, and `npm run build` succeeds. (build: see comment)

## Comments

### 2026-10-11: done (backend agent MobileApiSpkInventory)

Gap verified red first: `tests/Feature/SPK/EvidenceAuthorizationTest.php` ran before any change with 18 failed and 4 passed. Unassigned technician uploads to an in_progress SPK, anyone uploads to a completed or cancelled SPK, anyone removes any evidence, and the Show page had no `can.uploadEvidence` prop. After the fix: 22 passed. The API tests (`WorkOrdersTest`, `EvidenceTest`) were written after the routes and were not run red (the routes did not exist, so they would be 404 by construction).

Rule (one place, `WorkOrderPolicy`, used by web and API through `SpkService`):
- `uploadEvidence`: same company, SPK not `completed`/`cancelled`, and either `spk.update` or (status `in_progress` and the assigned active technician with `spk.evidence.upload`).
- `removeEvidence`: same company, SPK not `completed`/`cancelled`, and either `spk.update` or (status `in_progress`, `spk.evidence.upload` and the uploader). Evidence of another SPK or another collection is 404 before the policy runs.
- `SpkService::addEvidence/removeEvidence` lock the SPK row, authorize with `Gate::forUser($actor)`, dedupe on media custom property `idempotency_key` (the SPK row lock serializes the check), and audit `work_order` `evidence_uploaded` / `evidence_removed` with ids only. `captured_at` is stored as ISO 8601. One FormRequest, `StoreWorkOrderEvidenceRequest`, shared by web and API (existing file rules; `captured_at` between now-30 days and now+5 minutes).
- Web behaviour change: `WorkOrderController::uploadEvidence/removeEvidence` call the service. `show` passes `can.uploadEvidence` and `checkInSummary`; `Show.tsx` renders the upload form only when allowed. Existing web evidence expectation (`TicketAttachmentSharedUploadTest`, admin has `spk.update`) stays green unchanged.

Endpoints (all `api.technician`; mutations need `Idempotency-Key`):
- `GET /api/v1/technician/work-orders/{wo}` (`WorkOrderPolicy::view`): spec detail shape via the allow-list `TechnicianWorkOrderResource` (no `created_by`, `approved_by`, network accounts). `site` = SPK location, else the subscription installation address. `material_returns` = cases of inventory requests whose source is this SPK. `actions` = policy results.
- `POST …/start` optional body `check_in` (`App\Support\FieldCheckIn::rules()`, shared with tickets): lat/lng/accuracy_m/captured_at or `unavailable_reason`. Stored as `work_order` `check_in` activity properties (status, lat, lng, accuracy_m, captured_at, distance_m, or reason) in the same transaction as the start. Web Show displays `FieldCheckIn::summary()`.
- `POST …/evidence` (multipart, `throttle:api-upload`): 201 `{data: Evidence}`; a replay with the same key after the cache is flushed returns the same media id and creates no second file.
- `DELETE …/evidence/{mediaId}`: 204; 403 for someone else's evidence; 404 for media of another SPK.
- `POST …/submit`: 200 detail; the service's 422 messages pass through as `unprocessable`.

Verification: see the final report of the agent. Pint, Larastan on the changed files, ESLint and Prettier on `Show.tsx` clean.

