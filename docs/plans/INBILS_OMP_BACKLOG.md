# Antrean Inbils OMP

- imported_at: `2026-10-02T08:28:25+00:00`
- active_phase: none; all nine recovery lanes closed (2026-10-11)
- active_card: none;
- next_gate: Each lane closes only after focused gate on `inbils_test`, independent QA PASS and reviewer APPROVED; then the next lane starts.
- phase_boundary: Owner approved (2026-10-10, "Ya, lane aman saja") the recommended option of every Owner decision in `.scratch/<lane>/spec.md` for all nine recovery lanes, including `head-company`, `p2-export-drilldown` and `role-persona-sod` (closed 2026-10-10/11, see the closure sections below). Every card in this ledger is `done` or `superseded`; none is `needs_recovery`.
- single_writer: periksa claim/run Hermes dan proses sebelum tiap edit; hentikan source edit bila writer lain aktif.
- database_gate: MySQL `inbils_test` eksklusif untuk QA/PHPUnit/E2E, serial; jangan gunakan `inbils`.
- source: [`INBILS_OMP_SOURCE.json`](INBILS_OMP_SOURCE.json), snapshot impor immutable (110 tasks termasuk 18 ancestors, 93 internal links; board seluruhnya 377 links).
- provenance: OMP tool/delta/gate mengganti kewajiban Claude transcript bagi card migrasi. Hermes tidak diubah.
- status literal: `ready`, `pending`, `in_progress`, `blocked`, `needs_recovery`, `superseded`, `done`.

## Rantai aktif dan gate

Technician `t_da288dbd` APPROVED; Location `t_b64f8b75` APPROVED; DataTable A–E closed under approved replacement contracts.
Reporting P1 closed 2026-10-07 under the replacement contract: `t_316be420`, `t_fefb9a68`, `t_a7a1bda9`, `t_ecc8a59f`, `t_9e203f31` all `done`. The original Reporting acceptance remains unrecovered; approval replaced scope only.
Recovery lanes approved 2026-10-10 (recommended options): `reporting-v2`, `sales-attribution-base`, `kpi-sales`, `sidebar-ia`, `loading-progress`, `p2-persona-dashboard`, then `head-company`, `p2-export-drilldown`, `role-persona-sod` (16 cards); all closed `done` (one planner card `superseded` by contract).

Reporting contract: preserve existing filters, formulas, and visuals across all six report surfaces. Stock Card changes only the existing stock-movement ledger to server pagination and sorting; no opening balance or reconciliation feature. Audit Log changes only the empty-scope guard from raw SQL to Eloquent and retains its canonical DataTable. Existing SQL aggregates remain unchanged. No new exports. Technician Performance is complete and excluded from code changes.

Resume: hanya successor langsung setelah PASS; QA blocker mencegah reviewer; superseded bukan PASS. Tidak ada dispatcher/cron otomatis. OMP todo hanya tampilan sesi, status di sini checkpoint.

## Brief Technician Performance

### t_b51debaa

Only Technician.tsx: self iff finite numeric data.technician_id and filters.technician_id absent; omit technician_id on self GET. Team validate finite positive eligible technicians.data IDs and nonempty names; no auto-selection; disabled without valid target; use useForm errors/processing and server-filter-aligned metrics, responsive 320px. Keep backend unchanged.

Evidence/checkpoint: `Technician.tsx` self/team form, eligible validation and server-target metrics guard; `npx eslint resources/js/Pages/Admin/Reports/Technician.tsx && npm run typecheck` PASS (2026-10-02). Browser acceptance tetap gate tester.

### t_c2426c71

Only AdminLayout.tsx and Reports/Index.tsx: add report.technician capability to Finance section; index stays report.view. Direct sidebar link guarded by report.technician; technician card guarded likewise, Button asChild + Inertia Link.

Evidence/checkpoint: `AdminLayout.tsx` sidebar report.technician link; `Reports/Index.tsx` gated technician card with Button/Link. `npm run typecheck && npx eslint resources/js/Layouts/AdminLayout.tsx resources/js/Pages/Admin/Reports/Index.tsx` PASS (ESLint 0 errors, six existing unused-navigation-variable warnings).

### t_24cae357

Only tests/e2e/technician-performance.spec.ts: canonical fixtures and login helpers; admin creates two TP- organizations via app routes, associates demo manager/technician, asserts setup, tests self/team/sibling/no-org/staff/navigation/320px and restores original employee fields/deletes owned units in finally. inbils_test only.

Evidence/checkpoint: `tests/e2e/technician-performance.spec.ts` dibuat; canonical fixture, exact 403, setup/restore via app routes. `npx playwright test --list tests/e2e/technician-performance.spec.ts` menemukan 1 test; eksekusi browser di gate tester. ESLint spec tidak tercakup `tsconfig.json` (project-service parsing error), jangan klaim lint PASS.

### t_bc0fba41

Tester gate: serial isolated PHPUnit against inbils_test, typecheck, focused lint and one build, canonical E2E and smoke; preserve errors/HTTP exact 403 and browser proof. No source edits or production DB mutations.

Evidence/checkpoint (2026-10-03, tester PASS): DB identity `inbils_test` proven per run; full isolated PHPUnit `759 tests / 5512 assertions`, 0 failures, 0 errors (14 PHPUnit deprecations only). Focused Technician gates PASS: isolation 1/13, authorization 10/75, ReportQuery 3/6, capability route 1/11, subscription parity 13/13. Canonical E2E `tests/e2e/technician-performance.spec.ts` PASS (12.2s) against canonical `inbils_test` after `PHP_BINARY=/tmp/inbils-php-with-mysql` supplied `pdo_mysql`+DOM on the host and removal of a stale `inbils-tp-e2e` container holding :8010; fixtures reseeded via `node scripts/e2e-setup.mjs` (no preflight bypass). Spec fixes: open collapsed `Finance/Reports` sidebar, `getByLabel('To', {exact:true})` disambiguation, bounded Tab traversal for native date segments, distinct `?check=` query per expected 403 so console-error correlation stays 1-to-1. Baseline repairs: `applySort` now receives `CustomerAddress`/`ServiceSubscription` builders via `getQuery()` (missing `DB` import restored in SubscriptionController, PHPStan clean); `AssignRequestId` registered globally in `bootstrap/app.php`; `TicketPolicy::view` aligns detail scope with existing list/export scope for operational actors without `ticket.assign`; ticket fixtures pass `expectedAssignedTo`/fresh `expectedUpdatedAt`; `AdminMenuAuthorizationTest` asserts staff may open only permitted report children and gets exact 403 for technician/audit-log; Approval Inbox/My Work query reductions removed three eager-load queries, unified requester options into one tenant-scoped query, and eligibility checks capability before employee-profile existence. `npm run build` PASS; ESLint 0 errors (6 pre-existing `has*Navigation` unused-var warnings in AdminLayout); Pint applied only to files this phase touched — incidental reformats of Inventory requests, Reporting controller, seeder, trait, `routes/auth.php`, and notifications migration were reverted. QA (t_051e9897) and reviewer (t_da288dbd) still running; Location remains withheld.

Final rerun (2026-10-03): `760 tests / 5524 assertions`, no failures/errors, 14 PHPUnit deprecations; peak 145 MiB with a 512 MiB test-process limit. QA found valid underscore-bearing organization paths denied; the controller now allows `%`/`_` and the query escapes LIKE metacharacters before descendant matching. Regression proves a lookalike sibling descendant is denied (fails without escaping, passes with it). A subsequent full run exposed random company seed asset-code collision; sequential codes fixed it. One invalid concurrent QA/test run collided on `inbils_test` migrations; its result was discarded, and the final full suite ran alone. QA/reviewer agents must not execute DB tests concurrently. Canonical E2E after the wildcard fix passed; one teardown emitted a deadline warning, so final cleanup and rerun remain required. PHPStan evidence above used level 0, not a full Larastan gate.

Closure evidence: final canonical Technician test PASS 1/1 (13.0s); general canonical `npm run test:e2e:smoke` PASS 6/6 (7.8s), exit 0 and no teardown warning. Audit-log smoke now checks pagination/search/order/disclosure rather than incidental seeded record totals. Manual sanitized browser smoke captured the self report desktop and dark 320px, `scrollWidth=innerWidth=320`, dates present and selector absent; tab and server closed. An initial unsanitized manual launch attempted the wrong connection and failed before executing SQL; it was stopped and replaced by the canonical environment builder. App image rebuilt and only app recreated after final source changes; Compose healthy, `/up` 200, `/ready` 200 (database/cache/queue/failed_jobs/scheduler OK; backup unknown). Owned test containers, downloaded temporary PHP modules/launcher, and smoke launcher removed; no volumes removed. Future host E2E needs PHP MySQL and XML extensions again (temporary launcher is not retained).

### t_051e9897

Independent read-only QA: tenant and hierarchy fail-closed, KPI date/snapshot, direct routes/capabilities, UI and browser evidence, scope and DB identity; NEED_FIX with exact evidence if gap.

Evidence/checkpoint: initial `TechnicianQa` NEED_FIX (P2 valid underscore codes denied); literal LIKE escaping and regression applied. Independent `TechnicianQaRecheck` PASS, no actionable findings; code-only recheck accepted executed parent evidence. Configured Larastan level 5 PASS on changed controller/query/policy paths including HasIndexQuery; final focused relation/report tests 24/153 PASS. Canonical Technician and general smoke both PASS (closure evidence above).

### t_da288dbd

Independent final read-only review: source provenance OMP, complete tester/QA evidence, security and dirty baseline, phase scope and runtime health; APPROVED only on complete evidence.

Evidence/checkpoint (2026-10-03): independent `TechnicianFinalReview` APPROVED, no actionable findings after source and consumer review. Accepted executed evidence: full suite 760/5524, final relation tests 24/153, configured scoped Larastan level 5 PASS, build PASS, canonical Technician 1/1 and general smoke 6/6 PASS, browser desktop/mobile proof, final app health 200/200. No reviewer DB/test mutation. Technician phase COMPLETE; Location remains next, not executed.

## Fase Location, diotorisasi 2026-10-03

Location: `t_e0f1c27b` satu perubahan `stopImmediatePropagation()` pada saved marker → `t_57536305` build → `t_fce1ad69` canonical 2/2 → `t_f5ff6c39` QA → `t_b64f8b75` review. `t_19afa96d` generasi gagal, superseded, bukan PASS. Jangan ubah spec untuk memudahkan PASS.

Checkpoint 2026-10-03: Hermes read-only ownership 0 claimed tasks / 0 active runs. Delta source tepat satu call pada `LocationsMapView.tsx:181`: `stopPropagation()` menjadi `stopImmediatePropagation()`, sebelum clear draft/open popup. Reproduksi historis failed card diterima tanpa rerun baseline. Focused ESLint dan `tsc --noEmit` PASS; satu local `npm run build` PASS. Manifest dan `assets/Index-BcvEtu8A.js` lebih baru dari source, chunk memuat semantic anchor. Locked spec SHA-256 `13ac1fe7674e26a2b0d8728723c3d6017aeaade46a3f8dfc7b4360511ab6a9c3`; tidak diubah. Canonical prerequisite `--check` PASS; selected schema `inbils_test`, demo admin tersedia, tanpa reset/seed/DDL. Temporary PHP extensions di `/tmp` (tidak mengubah runtime host).

Tester 2026-10-03: `PHP_BINARY=/tmp/inbils-location-php-run npm run test:e2e -- tests/e2e/location-map-first.spec.ts` exit 1, **1/2 PASS** (9.9s). Empty-map/tile fallback PASS; saved marker test failed at line 169 because `.locations-map-popup` absent. Failure accessibility snapshot shows Area marker plus Draft location and `Tambah lokasi baru` at `-6.128394, 106.765437`, despite the saved fixture coordinates `-6.1234567, 106.7654321`. Thus one-call fix is not sufficient; no canonical retry, spec alteration, seed/reset, or phase advance. Failure artifacts retained under `test-results/location-map-first--kanban-58817--the-authoritative-tree-row-chromium/` per tester contract. Read-only trace investigation pending.

Read-only `LocationFailureScout`: trace resolves the saved marker div and records click `(784,517)`; fresh `Index-BcvEtu8A.js` served HTTP 200. Trace does not capture native `event.target`/composed path, so it cannot prove why capture handler did not match. No further termination-only remedy established; no speculative geometry change. **BLOCKED pending approval to diagnose native event ownership and remediate beyond the single-call scope.** QA/reviewer successors remain pending, not PASS. Canonical port 8010 released; no new runtime PID file; locked spec hash unchanged. Failed browser artifacts retained as required. App deployment deliberately held because tester failed; existing runtime is not claimed to contain this partial change.

Continuation authorized by user after blocked report (2026-10-03). Native-event browser instrumentation proved `pointerdown/mousedown` on saved marker SVG at `(784,517)`, followed by focus scroll moving marker from y499 to y365; `mouseup/click` then target map container. Runtime-only controlled experiment pre-focused map with `{preventScroll:true}` on saved-marker mousedown: popup present, draft 0, final focus still on saved marker. Source remediation stays in `LocationsMapView.tsx`: capture mousedown only for registered saved markers, focus map without scrolling, preserve native events and marker focus, remove listener at cleanup. No coordinate heuristics, no spec or backend changes. This material remedy authorizes one fresh build and canonical rerun; initial failed artifacts may be replaced by the canonical runner after their evidence is recorded here.

Remediation gate: focused ESLint, `tsc --noEmit`, fresh local build PASS. Canonical command unchanged, rerun after material source correction **2/2 PASS (4.5s), exit 0, no teardown warning**. Unmocked test-runtime browser: saved AREA-01 popup count 1, correct code/name, draft 0 before/after; edit shows authoritative address and parent 1; empty-map click creates draft 1, subsequent saved click clears it to 0; keyboard Enter opens popup; page errors empty. Dark 320px viewport shows correct popup and draft 0, document width 320 (no horizontal overflow). Screenshots inspected then disposable; canonical spec not modified. Read-only scout confirmed Leaflet `Map.Keyboard.js:77-88` focuses container and restores window offsets, not AdminLayout's nested main scroll. Final runtime refresh underway; independent QA active.

Runtime/cleanup: Docker app image rebuilt and only app recreated; Compose all healthy, `/up` 200 and `/ready` 200 (database/cache/queue/failed_jobs/scheduler OK; inherited backup unknown). Spec hash unchanged. Port 8010 free, diagnostic and final browser tabs/servers closed, no new runtime PID files. Canonical runner removed prior failure/report directories on passing rerun; failure details preserved above. Owned PHP extension packages, launcher and manual server removed from `/tmp`. No reset/seed/DDL, Hermes mutation, commit, or volume removal.

Closure: independent `LocationQa` PASS and `LocationFinalReview` **APPROVED t_b64f8b75**, no actionable findings after source/Leaflet consumer review. Both reviews read-only, no test/DB reruns. Five runnable Location cards complete; failed generation `t_19afa96d` remains superseded, not PASS. Location phase ends here; recovery backlog remains `needs_recovery`.

## Proof-of-work audit (2026-10-04, read-only)

Audit balik tanpa mengubah source/test/runtime: setiap klaim `done`/APPROVED pada fase yang sudah selesai diverifikasi terhadap artefak yang masih ada di tree. Tujuannya mendeteksi status done yang tidak lagi didukung kode (regresi/hapus), bukan menjalankan ulang suite.

- **Technician `t_da288dbd`** — source correspondence only: query, authorization test file, browser spec and Larastan level-5 configuration exist. This inspection does not independently verify the historical 760/5524 run. The earlier claim of 12 `@lifecycle` cases and attribution of the method-count difference to data providers were not established and are withdrawn.
- **Location `t_b64f8b75` APPROVED** — VERIFIED: `LocationsMapView.tsx:181-195` memuat `stopImmediatePropagation` pada handler saved-marker dan `mapContainer.focus({ preventScroll: true })` — persis perbaikan QA; spec `@kanban-t_40cf97ea` 2 case ada; smoke 6/6 terdiri dari harness 1 + ops-api-smoke 4 + audit-log 1 (sesuai klaim).
- **Dispatch/My Work `t_333b33e2` APPROVED (replacement)** — VERIFIED: `AssignDialog.tsx:112-152` memuat `submitGuardRef` guard sinkron (acquire sebelum `router.post`, release di `onFinish`); `MyWorkTest.php` 19 test methods; spec `dispatch-my-work.spec.ts` 13 skenario ada.
- **Notification `t_e7cc36eb` APPROVED (replacement)** — VERIFIED: `Notifications/Index.tsx` memakai `notifications.unreadCount` global untuk mark-all disabled state dan navigasi detail unread di `onSuccess` PATCH read; `NotificationCenterTest.php` ada.
- **DataTable A+B `t_a80ca6a1` APPROVED (replacement)** — VERIFIED oleh gate berjalan fase ini (125/1482, 11/11) + perubahan source terkonfirmasi di image produksi.
- This source-presence inspection neither revalidates historical runtime gates nor justifies changing completed statuses. No new runtime PASS is claimed; historical results remain attributed to their recorded runs.

Recovery assessment setelah gate Location (bukan izin coding): Dispatch/My Work; Notification Center; DataTable A/B, C, D, E; Reporting P1; Reporting V2; Sales dasar lalu KPI; Role persona/SoD; Sidebar IA; Loading/progress; Head Company; P2 export; P2 dashboard. Dependency source selalu menang. Cari kontrak exact legacy ID di docs/process, docs/plans, docs/claude-batches dan snapshot sebelum mengubah status. Head Company perlu keputusan tenant khusus; single-company v1 tetap berlaku.

## Dispatch/My Work recovery assessment (2026-10-03)

User authorized continuation after Location closure. Read-only SQLite transaction found zero claimed tasks and zero active runs. Snapshot comments 679 (`t_966400a1`) and 695 (`t_d604e679`) explicitly archive/supersede those ancestors after replacement chain `t_a07e05d4` → `t_53071d4c` → `t_7c704bf2` → `t_7901bb5f` → `t_bdcb3f31` completed. Do not treat archived ancestors as failed work to restart.

Live board read-only recovery: all five replacement tasks are `done`; source run 375 records two-file synchronous assignment guard and graceful Playwright teardown, build run 380 records fresh assets, tester run 381 records exact canonical Dispatch 3/3 exit 0 with native rapid-submit single POST and clean ownership teardown, QA run 382 PASS, reviewer run 383 APPROVED (135/135 selfchecks, typecheck, Prettier, zero orphan). These are **historical executed gates**, not new OMP test runs. Source comment 643's failed dedup run precedes the fresh-asset correction and is not the final result. Hermes source remains unchanged and import snapshot remains immutable.

Current source correspondence: `AssignDialog.tsx` synchronous `submitGuardRef` checked before submission, acquired before `router.post`, released in `onFinish`; `playwright.config.ts` retains SIGTERM/20s graceful shutdown. Existing Dispatch spec contains three cases: ticket/SPK→My Work, retryable validation, native rapid-submit dedup. Current SHA-256: AssignDialog `5ee6282850cc52322623aaeaa5c67e85f9b3426ae96ef431b176a9a2c04005e2`; Playwright config `3c554f1b72a802d228838dbfa15ebc602456331bf101182e1379ab42c5141d2e`; spec `c878145e17b6e9b439fc470b7732f60fec486a6717be32505384647adc151695`. This correspondence is not a claim of byte identity with historical builds or current full-suite PASS. Independent disposition review pending; no source edits, browser/DB fixture mutations, test reruns, or deployment during assessment.

Recovery limits: `DispatchRecoveryScout` searched exact current/legacy IDs, ancestor comments, `docs/process`, `docs/plans`, `docs/claude-batches`, and current implementation/tests. Original QA `t_310aa47f` and reviewer `t_7de61339` rubrics remain unavailable; direct comments 26/53 are freeze notes only. Referenced Ops `t_1471d346` payload/session dump is not in searched workspace sources. Ancestor comment 147 requires stale-409 reload success/failure/cancel, keyboard and 320px in addition to the three current browser cases. The later bounded 3/3 approval does **not** prove that wider legacy matrix. No explicit supersession of the two remaining QA/reviewer cards was found. Preserve `needs_recovery`; do not infer `done` or `superseded` for those cards merely from predecessor closure.

Independent `DispatchClosureReview` confirms conservative disposition: retain both old cards `needs_recovery`, no redispatch. Live/snapshot supersession comments close only the archived ancestors; no evidence proves the remaining QA/reviewer cards or wider browser matrix were superseded. Separate B1 successor chain remains historically APPROVED, not reopened. Missing prerequisite: original QA/reviewer scope and wider acceptance evidence, or explicit approval to replace it with a bounded current matrix (happy path, stale409 reload success/failure/cancel fail-closed, validation retry, rapid submit, keyboard, 320px, tenant/data safety and owned teardown). Assessment made no source/runtime/test/DB changes; no fresh functional PASS is claimed.

### Approved replacement matrix

User explicitly selected **Gunakan matrix pengganti**: happy path, stale conflict/reload success/failure/cancel fail-closed, validation retry, rapid submit, keyboard, 320px, tenant/data safety, owned teardown. This replaces unavailable QA/reviewer scope for these two cards only, without claiming the lost rubric was recovered. Existing three-case B1 lane stays historical; browser spec additions target missing behavior, no runner/config/backend changes unless a concrete in-scope bug is proven.

Fresh backend verification: zero-mount isolated `/test/app` sandbox, pinned MySQL `inbils_test`, `TestDatabaseIsolationTest.php`, `DispatchQueueTest.php`, `MyWorkTest.php`: **44 tests / 439 assertions PASS**. After PHPUnit schema lifecycle, existing DatabaseSeeder → DemoUserSeeder → E2eFixtureSeeder ran serially in canonical sanitized E2E environment, no manual migrate/reset; readback selected schema `inbils_test`, fixture ticket36/SPK24 company47 unassigned/open-generated. Canonical fixture records have no app unassign route; tests must not add irreversible lifecycle transitions. Writer only extends `tests/e2e/dispatch-my-work.spec.ts`; browser gate pending.

Replacement browser gate: canonical **13/13 PASS (38.1s), exit 0**. Existing 3 cases retained; 10 added cases cover Dispatch real stale optimistic token, Dispatch/My Work reload success/500/cancel, keyboard and 320px. My Work conflict response is deliberately simulated at the network boundary; backend stale/tenant proof is the separate 44/439 PHP gate, not a claim of real browser race. Cancellation invokes the actual exported built Inertia router `cancelAll()`; same-page filter navigation does not cancel async reload in this installed version. No retries or timeout inflation. Inner webserver emitted teardown deadline warning; post-run port 8010 and owned marker 3046271 were absent, outer cleanup verification pending. Manual 320px actual assignment dialog bounds x0..320/y46.5..753.5, Escape works. Scoped Prettier applied only to spec. Independent QA active; no production code changed.

Independent `DispatchMatrixQa` PASS, no actionable findings. Teardown caveat retained: inner deadline warning occurred, but subsequent owned marker absent, port 8010 free, and `/proc` enumeration found no same-repo e2e runner/webserver/Playwright/PHP serve processes. No arbitrary process kill or timeout change. Test sandbox/Composer helper and private PHP packages/launchers removed. Production code unchanged, so no affected Docker image to rebuild; Compose remains healthy and `/up`/`/ready` 200 (backup unknown inherited). Existing first three tests leave seeded records assigned as before; new matrix adds no successful business mutation. Final reviewer pending.

Closure: independent `DispatchMatrixFinalReview` **APPROVED t_333b33e2** for the explicitly authorized replacement matrix, no actionable findings. Both remaining Dispatch cards are now done under that replacement contract, not because original rubrics were recovered. Canonical/PHP/manual evidence and caveats above accepted; no current full-suite claim. Stop at phase boundary. Notification Center remains needs_recovery.

## Notification Center recovery (2026-10-03)

Read-only live SQLite and immutable snapshot agree: all five cards scheduled with generic recovery bodies, null results and only freeze comments 24/44/61/75/78. Chain: `t_97ad905e` → `t_d1939e94` → `t_3cfaed65` → `t_298aaec0` → `t_e7cc36eb`. Zero claimed tasks/active runs. `NotificationRecoveryScout` searched exact IDs and legacy references in project docs/process/plans/claude-batches; no original acceptance or terminal successor approval found. P3 advanced analytics/notifications is explicitly unrelated/deferred, not part of this phase.

Existing implementation mapped, not freshly verified: NotificationController actor-owned list/read/read-all; NotificationResource safe payload and tenant/policy checked ticket links; shared unread count; NotificationBell; Notifications/Index with pagination and read actions. Existing feature tests cover ownership/isolation, pagination, idempotency, malformed payload and link denial; existing two-case browser spec covers assignment→bell→inbox→read→detail at desktop/320px. That spec assumes an unassigned shared Dispatch ticket and exactly one notification; prior Dispatch gate left it assigned, so rerunning without lifecycle correction would be invalid. No tests, DB mutations, source edits or deployment performed in recovery. All five original cards remain `needs_recovery` until scope is approved or original contracts recovered.

User explicitly approved replacement: actor/company-owned persistent inbox, unread badge, pagination, idempotent read/read-all, malformed payload safety, tenant/policy fail-closed links, real assignment producer, keyboard/320px. Existing implementation is retained; no new channel/realtime/analytics. Initial isolated backend gate `TestDatabaseIsolationTest` + `NotificationCenterTest`: **7 tests / 131 assertions PASS**, pinned `inbils_test`. Existing seed classes restored browser prerequisites after PHPUnit, no manual reset/DDL. Actual inbox browser response proved shared `notifications.unreadCount` missing when the page resource overwrote it; backend actor-scoped count and off-page regression are being added. Frontend mark-all now keys off global unread count. New browser spec owns at most two tickets via application routes rather than shared Dispatch fixture/global notification totals; unread-detail persistence to be exercised before remediation.

Count regression proven failing-before (missing `notifications.unreadCount`, 1 test/15 assertions) and passing-after: isolated safety+Notification **7 tests / 148 assertions PASS**. Initial owned lifecycle canonical PASS (1/1, 16.0s), including keyboard/light-dark/320, read persistence, revoked links, real producer and owned cleanup. A deterministic held-read request check was then added because immediate PATCH+GET may cancel read on slower transport. Its first rerun stopped earlier: creating a new owned ticket returned 500, MySQL duplicate `9-TKT-2026-00001`; ticket numbering excluded soft-deleted tickets from previous successful cleanup. Minimal historical-code fix/regression in progress, no database reset to conceal it. Final QA remains pending these regressions.

### Notification replacement closure (2026-10-03)

The recovery and intermediate-run notes above remain historical evidence. The approved replacement contract, not a recovered original rubric, governs the five Notification cards. Backend `t_97ad905e`, UI `t_d1939e94`, tester `t_3cfaed65`, QA `t_298aaec0` and reviewer `t_e7cc36eb` are done for the bounded Notification acceptance below. App deployment/health is complete; the Notification replacement phase is COMPLETE and stopped at its boundary.

- Backend count fix: the inbox response preserves actor-scoped `notifications.unreadCount`; the off-page unread regression failed before the fix (1 test / 15 assertions, missing prop) and passed afterward with isolated `TestDatabaseIsolationTest` + `NotificationCenterTest`: **7 tests / 148 assertions PASS**, MySQL `inbils_test`. This is focused evidence, not a current full-suite pass.
- UI fixes: mark-all uses global unread count rather than unread rows on the current page. Unread View details navigates only in read-request `onSuccess`; already-read links navigate directly. The held-read check first proved the race: expected 0 detail GETs while PATCH was held, received 1; PATCH status -1 and GET 200. The final canonical run passes after ordering the navigation.
- Ticket lifecycle fix: the second owned-fixture creation exposed duplicate `9-TKT-2026-00001` after a previous fixture was soft-deleted. `TicketService` now includes `withTrashed()` in code generation. Final canonical creation and cleanup pass; the final focused PHP gate below also covers soft-deleted code and tenant cases. No database reset was used to hide the failure.
- Final canonical `tests/e2e/notification-center.spec.ts`: **1/1 PASS, 17.3s**, exit 0, no teardown warning. Owned application-route fixtures exercise the real assignment producer, read persistence, already-read links, held-read navigation, live denied target 403 and deleted target null-link behavior, keyboard actions and 1280px/320px light/dark views. The all-visible-read/global-unread-1 browser case uses response projection to prove UI behavior; it is not evidence of a real off-page browser fixture. Actual off-page unread data is covered by the backend regression.
- Build, scoped Notifications page ESLint and Pint controller/test checks PASS. Root ESLint project service excludes the E2E spec, so E2E ESLint is not claimed PASS. Manual browser checks show recipient inbox at 320px in light/dark without overflow or app errors, plus staff empty state.
- Deployment checkpoint: app-only rebuild/recreate completed; Compose reports all services healthy, `/up` 200 and `/ready` 200 with checks OK. Backup status is unknown, not a backup verification PASS. No application code changed after the build; the only subsequent change was test formatting.
- Mutation boundary: actor-wide read-all intentionally marks the shared `inbils_test` Demo Technician inbox read, including notifications beyond owned ticket fixtures. Cleanup deletes only the owned tickets; durable notification records and read state persist. Owned cleanup is not a claim of restoring the whole recipient inbox.
- Final focused PHP gate (`bg57`): isolation + NotificationCenter + TicketValidation **15 tests / 175 assertions PASS, 13.249s**, including soft-deleted ticket-code and tenant cases. Scoped Pint for four PHP files PASS. Existing seed classes restored `inbils_test` prerequisites after PHP; no manual DDL/reset.
- Owned temporary PHP launcher, extensions/packages, manual server and sandbox/composer containers removed; browser closed and report directories absent. Inspected inherited dirty tree (263 modified, 216 untracked) preserved; no cleanup of user files.
- Independent `NotificationQa` PASS, no findings, confidence 0.94; independent `NotificationFinalReview` APPROVED the explicitly authorized replacement contract, no findings, confidence 0.95. All five Notification cards are done. No fresh project-wide test claim, no change to immutable source JSON or Hermes, and no claim that original rubrics were recovered. Stop at the Notification phase boundary; DataTable recovery is next for assessment, not implementation authorization or current execution.

## DataTable A+B recovery (2026-10-03)

User requested continuation after Notification closure. Completed read-only assessment covers backend/UI A, backend/UI B, combined tester, QA and reviewer. No application source, tests, runtime or Hermes state changed for this assessment.

- Exact snapshot chain: `t_488f0166` → `t_b0a379d6` → `t_4bfa50a0`; `t_630f787c` → `t_d3fe1a15` → `t_4bfa50a0` → `t_8d234af5` → `t_a80ca6a1`. Two implementation lanes converge at the tester; no external backend predecessor is recorded.
- `DataTableContractScout` searched all seven current and legacy IDs in `docs/process`, `docs/plans`, `docs/claude-batches`, immutable snapshot bodies/comments/runs, and the live read-only board. Original bodies remain generic recovery warnings; no completed successor, tester/QA verdict or approval was recovered. Parent live SQLite read-only transaction confirmed all seven scheduled, null result/worker/current-run, and no running/claimed/pending run status in the board status inventory. Supervisor remains disabled/paused per scout inspection.
- `DataTableImplementationScout` mapped existing canonical primitives (`DataTable`, `useServerTable`, `toPagination`, `HasIndexQuery`) and Admin/customer/employee, Billing invoice and Service catalog list/export suites. These are existing implementation, not proof of original scope or fresh PASS. Existing browser spec `tests/e2e/datatable-customers.spec.ts` covers Customers only, not the full A+B surface.
- Relevant historical document `docs/plans/2026-08-22-table-button-implementation-graph.md` is explicitly planning-only, covers 41 surfaces and preserves unresolved scope choices. It cannot be substituted silently for the seven missing rubrics. `docs/ORCHESTRATION.md` is absent; no replacement file was invented.
- Missing decision: exact A+B page roster and required per-page search/filter/sort/pagination/export/permission matrix. Known source candidates are Users, Roles, Permissions, Customers, Organizations, Employees, Invoices, Service Packages, Bandwidth Profiles, Speed Profiles and SLA Tiers. Nested customer tables, Evaluations, Vehicles, Documents and Number Sequences are not automatically included; C–E, aggregate Receivables and embedded Invoice history remain outside this assessment.
- Disposition: all seven cards remain `needs_recovery`. No fresh tests/build/browser/deployment PASS is claimed. Source presence and scheduled status do not authorize a rewrite. Independent `DataTableRecoveryReview` PASS for assessment only, no findings, confidence 0.95; implementation requires original acceptance or an explicitly approved replacement matrix.

### Approved replacement contract

User explicitly selected **Gunakan kontrak pengganti** after the recovery review. Scope is exactly 11 indexes: Users, Roles, Permissions, Customers, Organizations, Employees (A); Invoices, Service Packages, Bandwidth Profiles, Speed Profiles, SLA Tiers (B). For each: existing search/filter behavior, backend sort allowlist, server pagination and query preservation, tenant/RBAC enforcement, index/export parity only where an export endpoint already exists, keyboard operation and 320px/light-dark resilience. Reuse existing code; fix only evidenced gaps. No new export feature, redesign, library, nested table migration or additional HR/admin pages. Backend/browser verification uses only serialized `inbils_test`; tester → independent QA → final reviewer, then stop before C. Original card rubrics remain unrecovered; completion will be attributed to this replacement contract.

Implementation checkpoint: isolated DB identity gate **1 test / 13 assertions PASS**; existing ten focused suites baseline **43 tests / 404 assertions PASS**. Added B regressions exposed ignored invoice date/customer search and Service page-size controls (10 rows despite requesting 25); package regression initially hit a random duplicate SLA fixture, corrected to explicit unique names, then reproduced the same page-size failure. Invoice index/export now share existing filters, and four Service controllers honor existing page-size selection. B focused gate **23 tests / 586 assertions PASS**. No full-suite claim.

Actual Employees browser showed a static table with no search/sort/pagination/export controls despite existing backend contracts; bounded canonical-page cutover in progress. Organization regression independently reproduced HTTP 500 from missing `SORTABLE`; its fixed-size pagination is also under repair. Actual Service Packages accessibility tree exposed unnamed Active/SLA and per-page selectors; label association and shared `Rows per page` accessible name added. Eleven-page browser matrix and A focused coverage remain pending; no final acceptance claimed.

### DataTable A+B replacement closure (2026-10-03)

Independent `DataTableABQA` **PASS** (no blocking findings) and `DataTableABReview` **APPROVED** the explicitly authorized replacement contract (no blockers; 2 minor advisories: `.omp/lsp.json` machine-absolute paths — resolved by gitignoring `.omp/`; Employees Edit keyed to `can.create` — both map to `employee.manage`, no escalation). All seven cards (`t_488f0166`, `t_b0a379d6`, `t_4bfa50a0`, `t_630f787c`, `t_d3fe1a15`, `t_8d234af5`, `t_a80ca6a1`) are done **under the replacement contract**; original rubrics remain unrecovered and are not claimed as recovered.

Evidence (parent-run, canonical commands, serialized MySQL `inbils_test`):

- Failing-before reproduced: Organization index HTTP 500 from missing `SORTABLE`; invoice date filter ignored (4 vs 2 rows) and customer search ignored (0 vs 1); Service `per_page=25` ignored (10 rows); invoice/employee CSV cells were whole-row JSON blobs — new Invoice column test **FAILS on HEAD** `ExportQuery` and passes after.
- Backend gate: **125 tests / 1482 assertions PASS** across all 27 export suites + Permission catalogue test + `TestDatabaseIsolationTest` (1/13 PASS, pinned `inbils_test`).
- Browser gate: canonical `npm run test:e2e -- tests/e2e/datatable-ab.spec.ts` **11/11 PASS**, one test per approved index, covering server search, keyboard sort both directions with `aria-sort`, `Rows per page` round-trip + reload query preservation, export parity only where the endpoint exists, and 1280px/320px light/dark with no horizontal overflow. Organizations and Invoices fixtures are created via app routes and deleted in `finally`.
- Fixes: Organization `SORTABLE` + `perPage`; Invoice shared `filteredQuery` for index/export; four Service controllers honor `per_page`; `ExportQuery::normalizeMap` expands callable row mappers per column (handles `'field' => 'Header'` and `'Header' => 'data.path'`); Employees page cut over to canonical DataTable; Service page sortKeys/fields aligned to `SORTABLE` + Resources (+`created_at`); Invoices page normalizes resource paginator via `toPagination`; shared `Rows per page` aria-label; Packages Active/SLA labels bound; duplicate Organizations per-page select removed.
- Tooling gates: tsc + scoped ESLint clean, scoped Pint clean, `npm run build` OK. Private E2E PHP launcher gained matching `bcmath.so` from the `php8.5-bcmath` .deb (production Dockerfile already installs bcmath; no system packages installed).
- Deployment: `app` service rebuilt/recreated only (no other service touched, no named volumes touched). Compose reports all five services Up (healthy). New image verified: bcmath loaded, `isDataPath` fix and Organization `SORTABLE` present in `/app`, asset manifest present. `/up` 200 and `/ready` 200 on the published port.
- Phase boundary: DataTable C–E recovery is the next phase and is NOT authorized; contract recovery first.

## DataTable C–E recovery assessment (2026-10-04)

Read-only assessment of the 15 C/D/E cards after A+B closure; NO application source, tests, runtime or Hermes state changed. Independent `DataTableCERecoveryReview` **PASS** for assessment only (no blocking findings, confidence 0.95).

- Exact chains (ledger predecessor/successor fields, matching snapshot links): C `t_dcf232f5` → `t_23e46c02` → `t_ee9c7127` → `t_43e33f86` → `t_39d87109`; D `t_69dd2c1a` → `t_40d41840` → `t_1b37b49b` → `t_0f7d528b` → `t_3b241cec`; E `t_ef68d35a` → `t_353135bf` → `t_333772ab` → `t_9bc28da4` → `t_9df467ae`. Three independent linear lanes; each terminal at its reviewer.
- `DataTableCERecoveryScout`: no recoverable original acceptance for any of the 15 cards. Snapshot bodies/comments/runs are generic recovery/freeze text with null run results; live board (read-only) shows all 15 scheduled, null result/worker/current-run, `created_by=restore-20260913`; exact-ID search of all 15 legacy IDs across `docs/process`, `docs/plans`, `docs/claude-batches` returns zero hits outside snapshot+ledger. `docs/plans/2026-08-22-table-button-implementation-graph.md` is planning-only (names candidate surfaces, not acceptance); `ENTERPRISE_ISP_EXECUTION_PLAN.md` and `docs/claude-batches/p1c-card.pretty.txt` belong to the different P1-C card `t_2b32433c`.
- `DataTableCEImplementationScout` (existing implementation, NOT recovered acceptance, NOT fresh PASS):
  - C Inventory: Products/Categories/Units controllers canonical (`HasIndexQuery`+`ExportQuery`, `inventory.export` gate) but their index pages are **static raw tables**; Stocks/Movements/Requests/Returns pages canonical (DataTable+useTableQuery); `StockController` index/movements use hardcoded `paginate(10)` without `HasIndexQuery`, and `whereRaw` low_stock filter (`StockController.php:236`) is raw SQL in the index path; Suppliers not implemented anywhere.
  - D: Tickets canonical (`TicketController` `SORTABLE`+export, `whereRaw('1 = 0')` at `:154` in index path); SPK page canonical but `WorkOrderController` uses `HasIndexQuery::applySort` **without a `SORTABLE` const** (latent Error, same class as the fixed Organization bug) plus hardcoded `paginate(10)`; Network Assets canonical (`SORTABLE`+export).
  - E: Invoice index already canonical under completed A+B; **Receivables page is a static raw table** (`BillingService::receivables()`, Eloquent buckets, no export); Invoice Show embedded Items/Payments tables static; embedded customer invoice history lives in `CustomerRelatedTables.tsx`.
- Test coverage exists per lane (Inventory `*ServerTableExportTest`, `TicketServerTableExportTest`, `WorkOrderIndexTest`, `ReceivablesTest`, `NetworkAssetServerTableExportTest`, plus lifecycle e2e specs) — existing coverage, not proof of original scope or fresh PASS.
- Disposition: all 15 cards remain `needs_recovery`. Candidate replacement matrix for the user gate: C = Products, Categories, Units, Stocks, Stock Movements, Inventory Requests (+ Returns tab); D = Tickets, SPK/Work Orders, Network Assets; E = Receivables, Invoice Show embedded Items/Payments. Out of scope unless explicitly added: Suppliers (unimplemented), Dispatch/My Work (separate completed phase), customer embedded invoice history (nested-table carve-out), A+B surfaces. Implementation requires original acceptance or an explicitly approved replacement matrix per lane (or combined) — user gate required; no coding authorized by this assessment.

Raw-SQL audit lanjutan (aturan pengguna: Eloquent saja, tanpa raw query; repo-wide grep `whereRaw|selectRaw|orderByRaw|DB::raw|havingRaw` pada app/ dan Modules/):

- **Blokir nyata di dalam path index/export**: `Modules/Inventory/app/Http/Controllers/StockController.php:236` (low_stock subquery raw); `Modules/Ticketing/app/Http/Controllers/TicketController.php:154` (`whereRaw('1 = 0')` handler nonaktif) — keduanya masuk kandidat kontrak pengganti lane C dan D.
- Empty-scope raw predicates exist in `app/Queries/Admin/{ApprovalInboxQuery,DispatchQueueQuery,InventoryRequestApprovalAdapter,MyWorkQuery}.php` and Reporting `AuditLogQuery`. A future change must preserve false-predicate semantics using Eloquent (for example a qualified-key empty `whereIn`); `limit(0)` is not an equivalent predicate and is not recommended for count/exists/pagination paths.
- Reporting and SPK contain raw aggregate expressions. These are existing code, not approved exceptions to the user's ORM-only instruction. No exemption is inferred from their placement in a query class; replacement must preserve results and performance within an authorized task.
- Tidak ada mutasi; audit ini hanya pendaftaran gap untuk gate berikutnya.

## Reporting P1 recovery assessment (2026-10-04)

- `ReportingP1RecoveryScout` searched the five current/legacy IDs in snapshot, planning/process/batch documents and the read-only live board. No original acceptance found; all five remain `needs_recovery`. Chain: `t_316be420` → `t_fefb9a68` → `t_a7a1bda9` → `t_ecc8a59f` → `t_9e203f31`.
- `ReportingP1ImplementationScout` maps six reports plus the navigation hub. Stock Card exists as a movement listing (`StockCardQuery`, `Reports/StockCard.tsx`); its query uses unpaginated `get()`. Opening-balance and reconciliation requirements are not recovered and must not be inferred from the card title. Audit Log already uses the canonical server table; Technician overlaps a completed phase.
- The scout's initial TS2322 claim was retracted as unverified; no compiler check ran in this assessment. Existing raw aggregates are not approved ORM-policy exceptions. No tests, runtime changes or new PASS claimed; independent Reporting recovery review has not run.
- Proposed C/D/E contracts are in `docs/plans/DATATABLE_CDE_REPLACEMENT_DRAFT.md`; they remain drafts. Fresh source reconciliation confirms Products/Categories/Units use Table with manual pagination, correcting the recovery review's conflicting claim that those pages already use canonical DataTable. Lane E's meaning of invoice history remains a user scope decision.

### Explicit execution approval (2026-10-04)

User selected **Jalankan C lalu D** in the scope decision: approve C (Products, Categories, Units, Stocks, Movements, Requests/Returns) then D (Tickets, SPK, Network Assets), existing exports only, Eloquent-only, serialized `inbils_test`, independent QA and reviewer per lane. This supersedes the pending C/D gate above; E and Reporting remain held. No original acceptance recovery is claimed.

### DataTable C replacement closure (2026-10-07)

- All five C cards are `done` under the user-approved replacement contract: backend `t_dcf232f5`, UI `t_23e46c02`, tester `t_ee9c7127`, QA `t_43e33f86`, reviewer `t_39d87109`. Original rubrics remain unrecovered. Scope: Products, Categories, Units, Stocks, Stock Movements, Requests and Returns; existing exports only. No Suppliers or Requests/Returns export was added.
- Products/Categories/Units now use the canonical server DataTable. Stocks/Movements honor allowlisted ordering and page size, preserve query state and share index/export ordering. Low-stock filtering uses Eloquent `whereHas`/`whereColumn` with tenant-preserving `withTrashed()` so historical membership is not lost for soft-deleted products.
- Requests/Returns align query defaults with fetched pagination and preserve their separate query namespaces. Extracting shared Requests constants/types out of the page module fixes the missing Requests Vite page entry. Products/Stocks/Movements export menus explicitly offer the supported CSV/PDF formats.
- Failing-before regression gate: **4 tests / 75 assertions, 3 failures**. Final isolated Inventory gate: **65 tests / 773 assertions PASS**; focused Stock gate: **7 tests / 394 assertions PASS**. Backend ran in Docker context `desktop-linux`, isolated project `inbils-c-verify`, MySQL `inbils_test`.
- Canonical `tests/e2e/datatable-c.spec.ts`: **9/9 PASS**, final `artifact://858`, including default Requests/Returns pagination, namespace preservation, explicit export formats, ordered off-page CSV and PDF responses, with clean teardown. Browser fixtures used the default Docker daemon's MySQL on host port **3307**, database `inbils_test`. Backend and browser therefore used **distinct database instances with the same database name**, not one shared instance.
- Evidence limits: CSV was verified as an HTTP attachment response, not a browser download event. Screenshots were captured but could not be visually inspected in the tool; viewport/overflow/theme assertions are not a visual screenshot review. These scoped gates do not establish a fresh full-suite pass or unmeasured coverage.
- `npm run build` PASS (`artifact://851`), retaining the existing approximately **809 kB main-chunk warning**; scoped ESLint and Pint PASS. Independent `InventoryCIndependentQA` **PASS** and `InventoryCFinalReview` **APPROVED** with no unresolved findings.
- Runtime closure: only `app` rebuilt/recreated with explicit Docker `--context default`; Compose healthy, `/up` **200**, `/ready` **200**. Backup status **unknown**, not a backup verification PASS. An initial `desktop-linux` app recreation failed because port 8080 was already served by the default daemon; the actual default runtime was then refreshed. No volume deletion.
- D is closed under the approved replacement contract. E and Reporting remain held; Hermes and the immutable source snapshot remain unchanged. Earlier C/D recovery dispositions above are historical, superseded by approval and the C/D closures.

### DataTable D replacement closure (2026-10-07)

- All five D cards are `done` under the user-approved replacement contract: backend `t_69dd2c1a`, UI `t_40d41840`, tester `t_1b37b49b`, QA `t_0f7d528b`, reviewer `t_3b241cec`. The original rubric remains unrecovered; no claim is made that it was recovered. E and Reporting remain held.
- Scope closed: Tickets, SPK/Work Orders, and Network Assets; existing exports only; Eloquent-only. Ticket query uses fail-closed Eloquent behavior instead of a raw-SQL false predicate, and priority is included in shared filters. SPK now has an explicit sortable allowlist, honors requested page size, and requires both view authorization and export authorization. Network Assets use canonical fields. All three index pages use server pagination and support CSV/PDF export.
- Shared export PDF path now produces a real Dompdf document using the existing escaped export view; PDF output is capped at 5,000 rows and remote resources are disabled. The 5,000-row cap's memory behavior was not measured.
- Baseline regression: `WorkOrderIndexTest` failed before the fix (1 test / 1 assertion; HTTP 500, undefined `SORTABLE`). Final isolated backend gate: **22 tests / 617 assertions PASS** on default Docker, isolated project `inbils-c-sandbox`, MySQL `inbils_test`. Coverage includes WorkOrder 11-row sorting/pagination/export, Ticket including raw-guard/priority, NetworkAsset, Inventory `StockServerTableExport` (7 tests including shared PDF regression), and direct SPK permission regression within WorkOrder suite. No full-suite claim.
- Canonical `tests/e2e/datatable-d.spec.ts`: **3/3 PASS**, `artifact://965`, on default MySQL instance `inbils_test`. Browser assertions cover all three pages and PDF signature/attachment behavior. No standalone `pdftotext` content smoke was run; PDF text extraction/content is not independently claimed.
- D QA initially returned NEED_FIX for an export-only authorization bypass. `WorkOrderController::export` now calls `Gate::authorize('viewAny', WorkOrder::class)` in addition to checking export permission; the regression demonstrates forbidden response for a user with export but no view access (pre-fix 200 vs expected 403), and focused test PASS (1 test / 1 assertion). Independent QA recheck PASS: prior blocker fixed, no actionable findings. Final `DFinalReview` APPROVED.
- Tooling/runtime evidence: scoped ESLint and Pint PASS; `npm run build` PASS (`artifact://914`, existing 809.80 kB chunk warning). Default Docker app rebuilt/recreated; `/up` 200 and `/ready` 200, Compose app healthy; backup status unknown. An immediate initial `/up` probe during startup returned 000; subsequent health checks passed. Do not infer a full-suite result.
- Stop at D boundary. No Hermes mutation, commit, or original rubric recovery is claimed.

### DataTable E replacement scope approval (2026-10-07)

- User approved E as a replacement-contract lane limited to **Receivables only**. Original acceptance for all five E cards remains unrecovered; approval replaces scope, not the missing original rubrics.
- Excluded: Invoice Show embedded Items/Payments and customer embedded invoice history. If separately authorized later, embedded tables must be server-side; this is a future constraint, not current scope or architecture authorization.
- All five E cards are `done` under that approved replacement contract: backend `t_ef68d35a`, UI `t_353135bf`, tester `t_333772ab`, QA `t_9bc28da4`, reviewer `t_9df467ae`. Receivables is Eloquent-only with no export. Reporting was subsequently approved under the separate replacement contract recorded below; stop at the E boundary for E work.
- Evidence: focused Receivables backend **8 tests / 131 assertions PASS**; Billing suite **72 tests / 456 assertions PASS**, both in Docker default isolated project `inbils-c-sandbox`, MySQL `inbils_test`. Canonical `tests/e2e/datatable-e.spec.ts` **4/4 PASS** (`artifact://1082`) on default host-port-3307 MySQL `inbils_test`: 320/1280 themes, sort/page/reload, full totals, customer role 403, and suspension modal label/reason validation, cancel, and Escape. Backend and browser used default Docker instance/project; no claim of a distinct tenant database deployment.
- Independent QA recheck PASS and final reviewer APPROVED. `npm run build` PASS (`artifact://1074`, existing 809.82 kB chunk warning); scoped ESLint and Pint PASS. No full-suite claim. Browser rendered empty-table state but no empty dataset was forced; no empty-data mutation was performed. Screenshot was captured without visual inspection. Do not claim more than the exercised checks.
- E closure does not authorize Invoice Show Items/Payments, customer embedded invoice history, or exports. Reporting P1 was separately approved under the replacement contract recorded above, without reopening Technician Performance or changing existing aggregates; no Reporting exports are authorized. Any embedded-table work requires separate authorization and must be server-side. Hermes and the immutable source snapshot were not changed.

### Reporting P1 replacement closure (2026-10-07)

- All five Reporting cards are `done` under the user-approved replacement contract: backend `t_316be420`, UI `t_fefb9a68`, tester `t_a7a1bda9`, QA `t_ecc8a59f`, reviewer `t_9e203f31`. Original rubric remains unrecovered.
- Stock Card: `StockCardQuery` validates the product with `Product::withTrashed()->where('company_id', …)->findOrFail()` (foreign-company product 404; soft-deleted same-company product keeps its ledger), filters by company/product/location/date, allowlists `created_at`/`quantity`/`balance_after` with an `id` tiebreak, and paginates with `withQueryString()`. New `StockMovementResource`; controller renders top-level `movements` paginator plus `filters`, accepts `sort`/`sort_by` and `direction`/`sort_dir`, page size limited to 10/25/50. `ReportFilterRequest` applies the Stock Card allowlist to both `sort` and `sort_by` on that route only. Page uses canonical `DataTable` + `useServerTable`, keeps the Product ID/From/To/Run form, carries a deep-linked `location_id` through sort/page visits, and shows empty/loading states.
- Audit Log: only `whereRaw('0 = 1')` → `whereKey([])`; DataTable unchanged. Technician Performance, aggregates, and exports untouched; no opening balance or reconciliation.
- Red-before proven: route sort `sort_by=quantity` 302 and foreign product 200 (expected 404); UI keys `sort=quantity&direction=asc` 302 "selected sort is invalid"; soft-deleted same-company product 404. Final backend gate `tests/Feature/Reporting` + `tests/Feature/ReportFilterValidationTest.php` **42 tests / 547 assertions PASS** in default Docker `inbils-c-sandbox`, MySQL `inbils_test`. Browser `tests/e2e/reporting-stock-card.spec.ts` **1/1 PASS** on `inbils_test`: product/date filter, keyboard Qty asc/desc, 10→25 page size, reload preservation, `location_id` kept through Balance sort, single-day inclusive date bound, 320/1280 light/dark overflow and focus indicator, no export control. E2E fixture `seedStockCardReportMovements` inserts 11 idempotent movements only. Location carry-through red-before was not separately proven in the browser.
- Follow-up browser gate after closure: `tests/e2e/reporting-stock-card.spec.ts` + existing `tests/e2e/reports-audit-log.spec.ts` **2/2 PASS** on `inbils_test`, confirming Audit Log UI sort/filter still validate after the `sort` rule became route-aware.
- Reviewer first returned NEED FIX (trashed product 404, `location_id` dropped on sort/page, first-run loading state unreachable); all three were fixed and the recheck returned APPROVED. Independent QA PASS. `npm run build` PASS (existing >500 kB chunk warning), scoped ESLint and Pint PASS. No full-suite claim. Inherited uncommitted Technician/route changes in the working tree are not part of this closure.
- Stop at the Reporting P1 boundary. Hermes and the immutable source snapshot were not changed.

### Reporting V2 replacement closure (2026-10-10)

- All five cards `done` under the owner-approved contract `.scratch/reporting-v2/spec.md` (recommended option of every decision; see its `## Approved decisions`): backend `t_1d565698`, UI `t_d698fabf`, tester `t_8aa097cc`, QA `t_ad34cbf5`, reviewer `t_a32e031e`. Original acceptance unrecovered.
- Delivered: code metric registry (`Modules/Reporting/app/Registry`, 39 metrics, all `v1` = current formulas verbatim, no query changed), read-only Data Dictionary page gated by `report.dictionary.view`, per-report permissions `report.business.view|asset.view|sla.view|stock_card.view` (`report.view` now gates only the hub; `report.technician`/`report.audit.view` unchanged), hub cards built server-side per capability (Audit Log card no longer shown to non-holders), formula/definition notes on Business/SLA/Asset/Stock Card. Seeder-only rollout keeps each seeded role's effective access; **existing deployments must re-run `php artisan db:seed --class=RolePermissionSeeder --force`** or the four reports 403 for everyone; custom roles holding `report.view` need the per-report permissions granted manually.
- Also fixed in this lane: Business/SLA filter row and Business/SLA/Asset KPI grids overflowed at 320px (pre-existing filter row, `grid-cols-1` missing).
- Evidence: red-before 65 tests with 19 errors + 12 failures against reconstructed pre-change code. Final focused gate `tests/Feature/Reporting` + `ReportFilterValidationTest` + `AdminMenuAuthorizationTest` + `Unit/RolePermissionSeederTest` **138 tests / 3436 assertions PASS** (includes new `ReportFormulaV1Test` 31/722 characterizing every Business/SLA/Asset v1 formula; 53 production mutations in a throwaway copy all killed); broader Admin/Unit/Tenant/API suites 258/2972 PASS earlier in the lane. Browser on `inbils_test`: `reporting-dictionary` 5/5, `auth-roles` 5/5, `technician-performance` 1/1, `reporting-stock-card` 1/1, `reports-audit-log` 1/1 (**13/13**). phpstan L5, Pint, Prettier, ESLint, typecheck, build PASS.
- QA first NEED FIX (no formula characterization tests) and reviewer P3 nits (duplicated label fallbacks, unused `MetricRegistry::find()`); all fixed; recheck PASS + APPROVED.

### Sales attribution base replacement closure (2026-10-10)

- All five cards `done` under the owner-approved contract `.scratch/sales-attribution-base/spec.md` (recommended options; see `## Approved decisions`): backend `t_ae471280`, UI `t_ffe9fc13`, tester `t_b70b570d`, QA `t_60912019`, reviewer `t_3d697b14`. Original acceptance unrecovered.
- Delivered: nullable `customers.acquired_by_user_id` (FK `restrictOnDelete`, index `(company_id, acquired_by_user_id)`, reversible migration `2026_10_10_000001`); permissions `customer.sales.assign` (admin, manager, staff) and `customer.sales.correct` (admin, manager); single eligibility source `CustomerAcquirerQuery` (active same-company user, active EmployeeProfile, `customer.create`) + rule `IsEligibleCustomerAcquirer`; single writer `AssignCustomerAcquirerAction` (create default = eligible creator; correction needs a reason; activity-log audit with old/new/reason); visibility (index column + filter, Show, Resource, export column) only for assign/correct holders; `Acquired by` composite on Create/Edit/Show. Deleting a user credited with customers is refused with a validation message from both `Admin\UserController::destroy` and `ProfileController::destroy`. Deploy: `php artisan migrate --force` then `php artisan db:seed --class=RolePermissionSeeder --force`.
- Evidence: focused + profile gate **118 tests / 1213 assertions PASS** in `inbils-c-sandbox`, MySQL `inbils_test`; earlier full Feature+Unit run 893/9765 with one unrelated failure (sandbox `APP_DEBUG=true` in `ExceptionReportingTest`). Browser on `inbils_test`: `customer-sales-attribution` 6/6 plus `customer-edit`, `customer-subscription`, `datatable-customers`, `datatable-ab`, `auth-roles` (**27/27**); the new spec's Show-row locator was fixed to allow the label's trailing space.
- Reviewer found P1: JSON `true` bypassed eligibility and was written as user id 1; QA found P2: profile self-delete raised a raw FK 500 after logout. Both red-first (302 instead of 422; SQLSTATE 1451) then fixed (strict id rule, `toId` ints/digit strings only, shared `ensureUserDeletable`). Recheck PASS + APPROVED.

### Sidebar IA replacement closure (2026-10-10)

- All four cards `done` under the owner-approved contract `.scratch/sidebar-ia/spec.md` (recommended options): UI `t_81b959f1`, tester `t_f106f2e8`, QA `t_c969a55f`, reviewer `t_77adedd5`. Original acceptance unrecovered.
- Delivered: typed nav config `resources/js/lib/navigation.ts` (an item without a gate does not compile) rendered by `AdminLayout`: standalone Dashboard + 6 titled groups (System/Developer folded into Company/Admin; Documents and Number Sequences moved there); empty groups not rendered (`SidebarSection` returns null); exactly one current item (longest href); Komponen only with `developer.components.view` and server-shared `developerGalleryEnabled` (local/testing); Dashboard needs `dashboard.view`; Permintaan Barang needs `inventory.request.view.own|all`; Service items `service.view|service.manage`; no new destinations.
- Evidence: `AdminMenuAuthorizationTest` 12→32 tests (739 assertions; every role × destination: shown → 200, hidden → 403, from real shared props); related nav/dashboard/queue suites 135/1890 PASS; `navigation.selfcheck.mjs` 19/19 (5 mutations detected); browser `sidebar-ia` + `auth-roles` + `technician-performance` + `billing-lifecycle` **22/22** on `inbils_test`. QA PASS (22 client/server gates compared), reviewer APPROVED.
- Known, not fixed (out of scope; follow-up candidates): Technician Performance link gate is the route gate `report.technician`, while the controller also needs technician role or a manager with an organization (demo manager without org gets 403; pre-existing, safe-fail); developer-gallery environment list duplicated in route and middleware (pinned by a test); mobile drawer links stay tabbable when closed, no Escape/focus trap, unlabeled nav landmarks (pre-existing).

### KPI sales replacement closure (2026-10-10)

- All five cards `done` under the owner-approved contract `.scratch/kpi-sales/spec.md` (recommended options 1A–8A; see `## Approved decisions`): backend `t_40d53598`, UI `t_c4b6971b`, tester `t_6c27c12e`, QA `t_58229ddb`, reviewer `t_54d82ff9`. Original acceptance unrecovered.
- Delivered: Sales KPI report `/admin/reports/sales` (`SalesKpiQuery`, Eloquent-only `withSum`/`withCount` over the read-only `SalesCustomer` view, bcmath totals; one row per acquirer + Unassigned; customers acquired, subscriptions activated, active MRR, invoiced, collected, outstanding, collection rate; totals over the full filtered set), capability `report.sales.view` (admin, manager), `ReportType::Sales`, 7 registry metrics `sales.v1` shown on the page and in the Dictionary, hub card, sidebar item (Finance/Reports, added by the integrator with its destination fixture). Deploy: re-run `php artisan db:seed --class=RolePermissionSeeder --force`.
- Evidence: `tests/Feature/Reporting` + `ReportFilterValidationTest` + `Unit/RolePermissionSeederTest` + `AdminMenuAuthorizationTest` **228 tests / 5396 assertions PASS** (incl. `SalesKpiFormulaV1Test`, `SalesKpiReportTest`; rerun after the `SalesCustomer` write guard and removal of a sandbox-only debug test); browser `reporting-sales-kpi` + `reporting-dictionary` + `sidebar-ia` + `auth-roles` + `technician-performance` **32/32** on `inbils_test` (two specs fixed to give each expected 403 a distinct URL so the fail-closed console audit can attribute it); nav selfcheck 19/19; Pint/Prettier/ESLint/typecheck/build PASS.
- The implementer stopped on a provider rate limit after its code and tests were in place; the integrator ran the gates, added the sidebar item, removed a sandbox-only debug test, and wrote the decisions/CHANGELOG. QA PASS and reviewer APPROVED (P3 paperwork + documenting the `SalesCustomer` exception, now done; the model refuses save/delete).

### Head Company closure (2026-10-11)

- All six cards `done` under the owner-approved contract `.scratch/head-company/spec.md` (recommended Q1–Q11; staged fail-closed → multi-company): `t_2cd5c5cc`, `t_25322033`, `t_c2a626e0`, `t_3e41cec6`, `t_521274c4`, `t_2aa65b96`.
- Delivered: fail-closed `BelongsToCompany`/`CompanyService` (no context → empty reads, 403 creates; seeders/commands use `runAs`), `companies.parent_company_id`, `company_user` membership with reversible backfill, session active company validated per request, POST `admin.company.switch` (uniform 403, audited), CLI-only `inbils:company:create-subsidiary|grant-membership|revoke-membership`, consolidated Business report for Head members (`report.consolidated.view`, currency-mismatch refusal, per-company timezone, CSV), company switcher + Consolidated page + hub/nav item. Role definitions editable only from a root company; user administration requires membership in every company the target belongs to.
- Evidence: 1253 tests green across batches (+9 `CompanyAdminAuthorityTest`), migrations up/down in sandbox; security isolation review BLOCKED (F1 global roles, F2 user admin) → fixed red-first → SIGNED for single-root installs; E2E broad set 84/87 then head-company + loading-progress 23/23 (×3 repeat clean), reporting-dictionary adjusted for the consolidated card.
- UI review (independent reviewer, 2026-10-11): APPROVED; one P3 a11y finding (switcher trigger disabled while switching lost keyboard focus) fixed with `aria-busy` + re-entry guard; head-company + reporting-dictionary E2E 12/12 on host PHP.
- Deploy: `php artisan migrate --force` + `php artisan db:seed --class=RolePermissionSeeder --force`. Never run DemoUserSeeder in production (now guarded).

### Role persona/SoD & P2 export closures (2026-10-10)

- Role persona/SoD (`t_594beec6`, `t_f4e0d462`, `t_81eccd66`, `t_3c2d67a9`): manager = controller (approve/reject/reverse/cancel, stock adjust approve), staff = operator (receive/issue/transfer, adjust request, prepare/issue); seeder `syncPermissions` on seeded roles; two-step stock adjustment (`stock_adjustment_requests`, requester≠approver, restrict FKs, admin break-glass audited); invoice canceller≠creator; request approver≠preparer≠issuer; non-admin cannot assign/edit admin users or protected roles; role-save and role-stacking SoD rules; `docs/SECURITY.md` matrix. Backend 714 tests green (+ Inventory 107/1079); reviewer NEED FIX (FK cascade, admin takeover, role stacking) fixed red-first → APPROVED. E2E auth-roles/sidebar-ia/dashboard-persona/inventory-lifecycle green.
- P2 export & drill-down (`t_a71f5680` superseded by contract, `t_63055b4e`, `t_9de05904`, `t_3cac1cdf`, `t_a51e7983`, `t_843a2fb0`): CSV/PDF export for all six reports + 33 drill lists with tie-out, permission `report.export` (admin, manager) double-gated, shared predicates (formulas unchanged, `ReportFormulaV1Test` green), 5,000-row cap marker, CSV injection escaping, server Audit Log export. 144/5038 + 109/1704 Reporting + module export suites green; E2E reporting-export 9/9. Reviewer P2 (lazy() chunk tie-break) fixed.
- Deploy: `php artisan migrate --force` then `php artisan db:seed --class=RolePermissionSeeder --force` (seeder now syncs seeded roles; custom roles untouched).

### Loading/progress & P2 Persona Dashboard closures (2026-10-10)

- Loading/progress (`t_b18f253d`, `t_b3c6843d`, `t_e161eb78`, `t_02ec133d`): All done. Radix Progress primitive, reduced-motion adjustments, DataTable skeleton row bounding, live regions 'Memuat data…', useListVisitState, useNavigationBusy. E2E 16/16 passed on `inbils_test`. QA PASS, reviewer APPROVED.
- P2 Persona Dashboard (`t_a5536496`, `t_0f437faa`, `t_f1c27ea9`, `t_336a4dc4`, `t_09021a86`, `t_40dc90eb`): All done. DashboardQuery, DashboardAdminStatsQuery, thin DashboardController, typed DashboardProps, cards by role capability, customer landing, overdue computation. 68 phpunit tests passed, E2E 13/13 passed on `inbils_test`. QA PASS, reviewer APPROVED.

### Hermes ↔ OMP reconciliation audit (2026-10-07)

- Read-only audit of `~/.hermes/kanban/boards/inbils/kanban.db` (Python `sqlite3` `mode=ro`); Hermes not modified. Board: 501 tasks — archived 240, done 164, scheduled 74, todo 13, blocked 7, triage 3. No claimed task, worker PID, or open run; gateways idle. Latest Hermes event (2026-10-07 18:54, `isp`) is a shared-worktree mutex gate that withholds dispatch while this OMP session owns the checkout.
- 97 Hermes-nonterminal tasks map to the ledger as follows: **44** `done` in OMP (Hermes card still `scheduled`/`todo` because Hermes is not mutated), **1** `superseded` (`t_19afa96d`), **45** `needs_recovery`, **7** absent from the ledger (Hermes Technician Performance micro-batches created after the 2026-10-02 import; see the section below).
- The 45 `needs_recovery` cards form nine lanes, each needing an owner-approved replacement contract before coding: Reporting V2 (5), customer-sales-attribution-v1 base (5), KPI sales (5), Role persona/SoD (4), Sidebar IA (4), Loading/progress (4), Head Company (6), P2 export/drill-down (6), P2 persona dashboard (6). Draft contracts and per-card tickets live in the local mattpocock tracker (`docs/agents/issue-tracker.md`): `.scratch/<lane>/spec.md` (`to-spec` template, Owner decisions first) and `.scratch/<lane>/issues/NN-<role>-<card>.md` (`Status: needs-owner-approval`, `Blocked by` = chain). Lanes: `reporting-v2`, `sales-attribution-base`, `kpi-sales`, `role-persona-sod`, `sidebar-ia`, `loading-progress`, `head-company`, `p2-export-drilldown`, `p2-persona-dashboard`. Drafts only; no approval implied. This ledger stays the status checkpoint.
- Hermes completed 9 tasks after the import (2026-10-02..05), including 8 TP micro-batches not in the snapshot. Those post-import deltas are limited to formatting/arity edits in `Technician.tsx` and the TP E2E spec; the inherited uncommitted Technician query/route/test changes trace to the pre-import TP chain and the OMP Technician closure (see the section below). All 17 post-import TP tasks are satisfied or superseded; none remains open.

### Hermes post-import Technician micro-batches (audit 2026-10-07)

- Scope: the 17 Hermes Technician Performance cards created after the 2026-10-02 snapshot (none present in `INBILS_OMP_SOURCE.json`; none referenced under `docs/`, `ROADMAP.md`, `TASKS.md`). Source: pre-exported `/tmp/inbils-hermes-audit/tp-post-import.json`; Hermes not modified; no DB, PHPUnit, browser or E2E run in this audit.
- Result: **0 open**, 7 satisfied by current tree, 10 superseded. The 7 still-open Hermes cards (`t_d0a986da`, `t_b5857588`, `t_fd841fed`, `t_9686fa41`, `t_923f9e68`, `t_06322706`, `t_95232a2c`) are historical/superseded or already covered by the closed OMP Technician phase (`t_da288dbd` APPROVED, 2026-10-03); none needs code. Hermes is not mutated, so they stay open there.

| Hermes id | Title (short) | Hermes status | Verdict | Evidence (current tree / check output) |
| --- | --- | --- | --- | --- |
| `t_a634be02` | TP-UI1 FORMAT: Prettier on `Technician.tsx` | done | satisfied | `npx prettier --check` on `resources/js/Pages/Admin/Reports/Technician.tsx`: all files formatted, exit 0. `Technician.tsx` mtime 2026-10-02 20:02 = run window 20:00-20:03. |
| `t_abaa66c7` | ADOPT TP-UI1 DELTA: self/team report | archived | superseded | By `t_a634be02`. Contract present: `Technician.tsx:41-44` (self iff finite numeric `data.technician_id` and `filters.technician_id` absent), `:98-105` (self submit omits `technician_id`), `:123` (selector hidden on self), `:161` (Run disabled without valid target), `:45-61` (validated eligible rows, server target only). |
| `t_2b584fcc` | TP-UI3A: format E2E delta | done | satisfied | `npx prettier --check tests/e2e/technician-performance.spec.ts`: exit 0. |
| `t_d0a986da` | RECOVER TP-UI3: adopt canonical E2E spec | blocked | superseded | Timed-out generation (run 599, 647s>600s), replaced by `t_2b584fcc`/`t_7ff3c958` per Hermes comment. Spec still holds each listed behaviour: self hides selector `spec:169` and omits `technician_id` `:180`; manager option check `:192`; denied sibling `:218-224`; staff `:237-242`; 320px/dark layouts `:183-184`, `:208-209`; console/page-error capture from `tests/e2e/support/fixtures.ts:291-301` via the spec's `./support/fixtures` import. |
| `t_d8b89956` | TP-UI3C: layout selector assertions | archived | superseded | Partial (self `false` only); remainder done by `t_7ff3c958`. |
| `t_7ff3c958` | TP-UI3D: manager layout selector boolean | done | satisfied | `spec:184` `assertLayout(page, width, dark, false)` (self), `:209` `assertLayout(page, width, dark, true)` (manager); signature `:62-67` requires the 4th arg. `npx tsc --noEmit` exit 0 (note: `tsconfig.json:19` includes only `resources/js`, so tsc does not type-check the spec). |
| `t_b5857588` | TP-UI3B: semantic adoption of E2E spec | blocked | superseded | Its one defect (arity at two `assertLayout` calls) is fixed at `spec:184,209` by `t_7ff3c958`; writer-capability blocker is moot. `npx playwright test --list tests/e2e/technician-performance.spec.ts`: 1 test at `spec:122` (list only, not executed). |
| `t_c7a60b1d` | TP-TEST-A: backend RBAC/formula + static gates | done (FAIL) | superseded | Hermes result FAIL (Larastan `ReportController` property.notFound; Prettier on spec). Both blockers now clear (checks below). Behaviour covered by `TechnicianPerformanceAuthorizationTest.php` (11 methods: self default `:45`, other 403 `:63`, staff/customer `:74`, cross-company `:86`, manager same/descendant `:110`, LIKE-metachar `:153`, no-org `:212`, malformed path `:223`, inactive target `:244`, inclusive date bounds `:263`) plus `TechnicianReportCapabilityRouteTest.php:23`. Not re-run here. |
| `t_db59cd41` | OPS TP-TEST-A0: restore PHP dev toolchain | done | satisfied | `vendor/bin/phpstan`, `phpunit`, `pint` present and executable on host (mtime 2026-10-02 22:32). `phpstan` runs in `inbils-c-sandbox` (PHP 8.4.26), result below. |
| `t_923f9e68` | FIX TP-STATIC-A: Larastan controller defect | blocked | superseded | Wrong-contract run 608, no delta. Defect gone via `t_8d8cd025` (below). |
| `t_06322706` | FIX TP-STATIC-A2: type-safe organization path | blocked | superseded | Run 609 timed out, no delta. Same. |
| `t_95232a2c` | TP-STATIC-A3: exact typed narrowing | triage | superseded | Its literal edit (import `OrganizationUnit` + `instanceof` narrowing) was never applied: `ReportController.php` has no `OrganizationUnit` reference; `:85` still `$profile?->organization?->path`. Larastan is clean anyway via the PHPDoc in `t_8d8cd025`. Hermes records runs 610/611 blocked by the scanner before any delta. |
| `t_8d8cd025` | TP-STATIC-A4: adopt typed organization relation | done | satisfied | `app/Models/Core/EmployeeProfile.php:47` `/** @return BelongsTo<OrganizationUnit, $this> */` on `organization()` `:48-51`. Fail-closed 403 intact: `ReportController.php:64-94` (`abort_unless` at `:66,:86,:91`, `isValidOrganizationPath` `:101`) and `TechnicianPerformanceQuery.php:60,130,167`. Larastan result below. |
| `t_f6d1c686` | TP-STATIC-B: canonical-format E2E spec | done | satisfied | Prettier on the spec exit 0 (below). Hermes wrote no edit (file already formatted on 2026-10-05 21:47); spec mtime 2026-10-03 04:48. |
| `t_745189ac` | TESTER TP-STATIC-R: replacement gate | done | satisfied (historical) | Hermes reported 12/12 PHPUnit (98 assertions) on `inbils_test`, Larastan, Pint, Prettier, tsc, ESLint. Independently re-checked here: Larastan, Prettier, tsc only. PHPUnit, Pint, ESLint not re-run. Test count matches the file set: 11 methods + 1 = 12. |
| `t_fd841fed` | TP-TEST-B: canonical E2E runtime readiness | blocked | superseded | Hermes run 2026-10-05: `NEED_FIX`, host PHP 8.5.4 lacks `pdo_mysql` (re-confirmed here: `php -m` lists `PDO` only; `/tmp/inbils-php-with-mysql` absent, no `/tmp/inbils-php*`). Superseded by the OMP canonical Technician run: ledger Technician `t_bc0fba41` (PASS 12.2s, then final 1/1 PASS 13.0s, `PHP_BINARY=/tmp/inbils-php-with-mysql`, `inbils_test`; see Brief Technician Performance). Host capability gap remains an environment fact, not a TP product gap. |
| `t_9686fa41` | TP-TEST-C: canonical browser acceptance | todo | superseded | Same OMP run (`t_bc0fba41` 1/1, plus smoke 6/6, desktop and dark 320px manual proof). Not executed here. Note: its "no Draft/location crossover" clause is Hermes-only and not asserted in the spec (`grep -i "draft\|location"` on the spec: no matches); not in the OMP contract. |

Counts: satisfied 7 (`t_a634be02`, `t_2b584fcc`, `t_7ff3c958`, `t_db59cd41`, `t_8d8cd025`, `t_f6d1c686`, `t_745189ac`), superseded 10 (`t_abaa66c7`, `t_d0a986da`, `t_d8b89956`, `t_b5857588`, `t_c7a60b1d`, `t_923f9e68`, `t_06322706`, `t_95232a2c`, `t_fd841fed`, `t_9686fa41`), open 0.

Static checks run in this audit (no DB, no PHPUnit, no E2E, no browser), 2026-10-07:

- `docker --context default start inbils-c-sandbox` (already up); `docker --context default cp` of `Modules/Reporting/app/.`, `Modules/Reporting/routes/.`, `app/Models/Core/.` into `/test/app/<same path>/`; md5 of `TechnicianPerformanceQuery.php`, `ReportController.php`, `EmployeeProfile.php` identical host vs container.
- `docker --context default exec --workdir /test/app inbils-c-sandbox vendor/bin/phpstan analyse Modules/Reporting/app/Queries/TechnicianPerformanceQuery.php Modules/Reporting/app/Http/Controllers/ReportController.php app/Models/Core/EmployeeProfile.php --memory-limit=1G --no-progress` (config `phpstan.neon`: Larastan, level 5, `app/` + `Modules/`, tests excluded): `[OK] No errors`, exit 0.
- `npx prettier --check tests/e2e/technician-performance.spec.ts resources/js/Pages/Admin/Reports/Technician.tsx`: "All matched files use Prettier code style!", exit 0.
- `npx tsc --noEmit`: exit 0.
- `npx playwright test --list tests/e2e/technician-performance.spec.ts`: 1 test listed; not executed.
- Not run: PHPUnit, Pint, ESLint, any E2E; no claim about them beyond the historical Hermes/OMP evidence cited above.

Dirty-tree attribution (`git diff`, mtimes, Hermes run windows; Hermes timestamps are local +0700):

- Hermes post-import source deltas are limited to formatting/arity edits: `Technician.tsx` (`t_a634be02`, plus a partial wrap by `t_abaa66c7`) and the E2E spec (`t_2b584fcc`, `t_7ff3c958`, partial `t_d8b89956`). Cards with source scope but no delta: `t_923f9e68` (run 608), `t_06322706` (run 609), `t_95232a2c` (runs 610/611 blocked before execution), `t_d0a986da` (partial, superseded), `t_b5857588` (blocked, no edit). `t_c7a60b1d`, `t_db59cd41`, `t_745189ac`, `t_8d8cd025`, `t_f6d1c686` were read-only or made no edit.
- `Modules/Reporting/app/Queries/TechnicianPerformanceQuery.php` (+134/-24): mtime 2026-10-03 02:01, after Hermes `t_c7a60b1d` (finished 2026-10-03 00:13 against the earlier version). The `escapeLikeWildcards` hunk matches the OMP QA fix recorded in Brief Technician Performance (`t_bc0fba41` final rerun, `t_051e9897`). Base hunks (`eligibleTechnicians`, `canSelect`, company scoping) trace to the pre-import TP-B/TP-C chain (snapshot `t_82895309`, `t_3a6d2091`) [INFERENCE: provenance by timing and task scope, not by attestation]. Not Hermes post-import.
- `Modules/Reporting/routes/web.php` (+8/-2, dedicated `can:report.technician` and `can:report.audit.view` routes): mtime 2026-10-02 06:18, before the snapshot capture (08:28Z = 15:28 local) and before the first post-import card (19:54). Pre-import TP-B/capability work (`TechnicianReportCapabilityRouteTest.php` mtime 06:33) [INFERENCE]. The `report.audit.view` route hunk is Audit Log scope, not TP.
- `tests/Feature/Reporting/ReportQueryTest.php` (real technician profile fixture for `TechnicianPerformanceQuery::execute`): mtime 2026-10-02 15:55, before all post-import cards and not in the scope of any of the 17. Pre-import TP-B/TP-C chain or the earlier OMP session; exact author unattributable [INFERENCE].
- `tests/Feature/Reporting/TechnicianPerformanceAuthorizationTest.php` (untracked): mtime 2026-10-03 03:05. Hermes `t_c7a60b1d` ran 11 tests / 86 assertions at 2026-10-03 00:07; Hermes `t_745189ac` ran 12 / 98 on 2026-10-05, so the extra test (LIKE-metachar lookalike, `:153`) was added between them by the OMP QA fix, not by Hermes. `TechnicianReportCapabilityRouteTest.php` (untracked): mtime 2026-10-02 06:33, pre-import.
- Adjacent TP files (not in the audit list but relevant): `app/Models/Core/EmployeeProfile.php` PHPDoc, mtime 2026-10-03 04:26; Hermes `t_8d8cd025` calls it an "unowned generic PHPDoc annotation", so Hermes adopted it and did not write it; attributable to the OMP TP closure [INFERENCE from ledger closure plus mtime]. `tests/e2e/technician-performance.spec.ts` (untracked), mtime 2026-10-03 04:48: Hermes formatting/arity hunks of 2026-10-02 21:34-21:54 (`t_2b584fcc`, partial `t_d8b89956`, `t_7ff3c958`) are the base; final content includes OMP fixes in Brief Technician Performance (`t_bc0fba41`: collapsed sidebar, `getByLabel('To', {exact:true})`, bounded Tab). `Technician.tsx`, mtime 2026-10-02 20:02: matches `t_a634be02`. `ReportController.php` (mtime 2026-10-07 22:47) mixes TP authorization with the Reporting P1 and Audit Log hunks; Larastan result above covers the whole file.
- Nothing was modified or reverted by this audit other than this ledger section.

### t_488f0166 / t_630f787c / t_b0a379d6 / t_d3fe1a15 / t_4bfa50a0 / t_8d234af5 / t_a80ca6a1 — DataTable A+B (backend A, backend B, UI A, UI B, tester, QA, reviewer)

- source_status: `scheduled`; omp_status: `done` (replacement contract, user-approved); phase: `datatable-ab-replacement`
- acceptance: 11-index matrix above; all 11 pages PASS in backend gate 125/1482 and canonical E2E 11/11 on `inbils_test`; QA PASS; reviewer APPROVED
- evidence: failing-before reproductions, backend/browser gates, QA and reviewer verdicts in the closure section









## Kartu non-final saat impor

Setiap `source status`, body, result, komentar, run dan dependency asli tersimpan di snapshot; `scope/acceptance` untuk card detail merujuk `tasks[].body` dan brief di atas. Untuk recovery body tidak memuat acceptance asli: tidak boleh menebak dari judul/status. Evidence baru ditambahkan per card setelah benar-benar dijalankan.

### t_02ec133d — [t_5d1e08fb] REVIEWER UI: final loading dan progress informatif

- legacy_id: `t_5d1e08fb`
- source_status: `scheduled`; omp_status: `done`; phase: `loading-progress-replacement`
- predecessor: `t_e161eb78`; successor: tidak ada
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/loading-progress/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_051e9897 — QA-ISP TECHNICIAN PERFORMANCE: tenant hierarchy and KPI integrity audit

- legacy_id: tidak tersedia
- source_status: `todo`; omp_status: `done`; phase: `technician-performance`
- predecessor: `t_bc0fba41`; successor: `t_da288dbd`
- scope/acceptance: snapshot tasks[t_051e9897].body; evidence: independent QA recheck PASS after wildcard fix; no remaining code findings.
- next: Final reviewer receives complete clean verification evidence.

### t_09021a86 — [t_5c5165fc] QA-ISP P2: persona dashboard dan exception queues

- legacy_id: `t_5c5165fc`
- source_status: `scheduled`; omp_status: `done`; phase: `p2-persona-dashboard-replacement`
- predecessor: `t_336a4dc4`; successor: `t_40dc90eb`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/p2-persona-dashboard/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_0f437faa — [t_f9783de7] P2 BACKEND: persona dashboard read models dan exception counts

- legacy_id: `t_f9783de7`
- source_status: `scheduled`; omp_status: `done`; phase: `p2-persona-dashboard-replacement`
- predecessor: `t_a5536496`; successor: `t_f1c27ea9`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/p2-persona-dashboard/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_0f7d528b — [t_007527f4] QA-ISP DATATABLE P1 D: Ticket/SPK/Network Asset

- legacy_id: `t_007527f4`
- source_status: `scheduled`; omp_status: `done` (user-approved replacement contract); phase: `datatable-d-replacement`
- predecessor: `t_1b37b49b`; successor: `t_3b241cec`
- scope/acceptance: D replacement matrix; original rubric unrecovered; independent `OperationalDQA` PASS and `DQARecheck` PASS; see DataTable D replacement closure (2026-10-07).
- next: DONE; final reviewer `DFinalReview` APPROVED.

### t_19afa96d — Verifikasi canonical location map 2/2 tanpa draft fall-through

- legacy_id: tidak tersedia
- source_status: `triage`; omp_status: `superseded`; phase: `location-map`
- predecessor: `t_20404a5f`, `t_4af24688`, `t_6243f5a7`, `t_64e336c9`, `t_6538f6df`, `t_a894f0a6`, `t_b3f925df`, `t_da749efc`; successor: tidak ada
- scope/acceptance: snapshot tasks[t_19afa96d].body; evidence: historical source only, belum PASS OMP
- next: Replacement FRESH8A; bukan PASS.

### t_1b37b49b — [t_b334b92c] TESTER DATATABLE P1 D: Ticket/SPK/Network Asset

- legacy_id: `t_b334b92c`
- source_status: `scheduled`; omp_status: `done` (user-approved replacement contract); phase: `datatable-d-replacement`
- predecessor: `t_40d41840`; successor: `t_0f7d528b`
- scope/acceptance: D backend/browser gates PASS, 22 tests / 617 assertions and canonical E2E 3/3; see DataTable D replacement closure (2026-10-07).
- next: DONE; QA PASS and final reviewer APPROVED.

### t_1d565698 — [t_7c73b65d] P1 BACKEND REPORTING V2: formula-version, granular capability, data dictionary

- legacy_id: `t_7c73b65d`
- source_status: `scheduled`; omp_status: `done`; phase: `reporting-v2-replacement`
- predecessor: tidak ada; successor: `t_d698fabf`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/reporting-v2/spec.md`; original acceptance unrecovered.
- next: DONE; see Reporting V2 replacement closure (2026-10-10).

### t_23e46c02 — [t_75970a34] UI DATATABLE C: Inventory operational tables

- legacy_id: `t_75970a34`
- source_status: `scheduled`; omp_status: `done` (user-approved replacement contract); phase: `datatable-c-replacement`
- predecessor: `t_dcf232f5`; successor: `t_ee9c7127`
- scope/acceptance: canonical Inventory UI under the approved C matrix; original rubric unrecovered; see DataTable C replacement closure (2026-10-07).
- next: DONE; QA PASS and reviewer APPROVED.

### t_24cae357 — MICROBATCH TP-UI3: canonical Technician Performance E2E spec

- legacy_id: tidak tersedia
- source_status: `todo`; omp_status: `done`; phase: `technician-performance`
- predecessor: `t_c2426c71`; successor: `t_bc0fba41`
- scope/acceptance: snapshot tasks[t_24cae357].body; evidence: one canonical spec discovered; browser execution pending tester.
- next: `t_bc0fba41` tester gate.

### t_25322033 — [t_50952f43] BACKEND P1 Head Company: consolidated reporting pilot dan export parity

- legacy_id: `t_50952f43`
- source_status: `scheduled`; omp_status: `done`; phase: `head-company-replacement`
- predecessor: `t_2cd5c5cc`; successor: `t_3e41cec6`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/head-company/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-11.

### t_298aaec0 — [t_93a4ec02] QA-ISP P1: Notification Center persistent

- legacy_id: `t_93a4ec02`
- source_status: `scheduled`; omp_status: `done`; phase: `notification-center-replacement`
- predecessor: `t_3cfaed65`; successor: `t_e7cc36eb`
- scope/acceptance: independent `NotificationQa` PASS for the user-approved Notification replacement contract, findings empty, confidence 0.94; focused PHP 15/175 and canonical/deployment evidence recorded above. Original rubric remains unrecovered.
- next: reviewer `t_e7cc36eb` APPROVED; phase complete, stop at Notification boundary.

### t_2aa65b96 — [t_1b915d8c] REVIEWER Head Company: final architecture dan release-readiness decision

- legacy_id: `t_1b915d8c`
- source_status: `scheduled`; omp_status: `done`; phase: `head-company-replacement`
- predecessor: `t_521274c4`; successor: tidak ada
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/head-company/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-11.

### t_2cd5c5cc — [t_885ed187] BACKEND P0 Head Company: hierarchy, membership, fail-closed CompanyContext

- legacy_id: `t_885ed187`
- source_status: `scheduled`; omp_status: `done`; phase: `head-company-replacement`
- predecessor: tidak ada; successor: `t_25322033`, `t_c2a626e0`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/head-company/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-11.

### t_316be420 — [t_d0d14864] P1 BACKEND REPORTING: canonical filters, Stock Card reconciliation dan server table

- legacy_id: `t_d0d14864`
- source_status: `scheduled`; omp_status: `done`; phase: `reporting-p1-replacement`
- predecessor: tidak ada; successor: `t_fefb9a68`
- scope/acceptance: user-approved replacement contract; all six reports preserve current filters, formulas, and visuals. Backend scope is limited to server pagination/sorting for the Stock Card movement ledger and replacing only the Audit Log empty-scope guard with Eloquent. No opening balance/reconciliation work, no aggregate changes, no exports, no Technician Performance edits. Original acceptance unrecovered.
- next: DONE; see Reporting P1 replacement closure (2026-10-07).

### t_333772ab — [t_9b3ec811] TESTER DATATABLE P1 E: Receivables dan Invoice history

- source_status: `scheduled`; omp_status: `done` (user-approved replacement contract); phase: `datatable-e-replacement`
- predecessor: `t_353135bf`; successor: `t_9bc28da4`
- scope/acceptance: Receivables-only replacement; original acceptance unrecovered. No export; embedded tables excluded. Focused backend 8/131 PASS and canonical E2E 4/4 PASS; QA recheck PASS; reviewer APPROVED. See DataTable E replacement closure (2026-10-07).
- next: DONE; E closed at approved Receivables-only boundary.

### t_333b33e2 — [t_7de61339] REVIEWER FINAL REMEDIASI: Dispatch/My Work

- legacy_id: `t_7de61339`
- source_status: `scheduled`; omp_status: `done`; phase: `dispatch-my-work-replacement-matrix`
- predecessor: `t_d8015197`; successor: tidak ada
- scope/acceptance: explicit user-approved replacement matrix above; independent `DispatchMatrixFinalReview` APPROVED, QA PASS, canonical 13/13 and PHP 44/439 evidence accepted with recorded limits.
- next: stop at replacement phase boundary; Notification Center recovery next.

### t_336a4dc4 — [t_43a770fe] TESTER P2: persona dashboard dan exception queues

- legacy_id: `t_43a770fe`
- source_status: `scheduled`; omp_status: `done`; phase: `p2-persona-dashboard-replacement`
- predecessor: `t_f1c27ea9`; successor: `t_09021a86`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/p2-persona-dashboard/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_353135bf — [t_9371744e] UI DATATABLE E: Receivables dan Invoice embedded history

- source_status: `scheduled`; omp_status: `done` (user-approved replacement contract); phase: `datatable-e-replacement`
- predecessor: `t_ef68d35a`; successor: `t_333772ab`
- scope/acceptance: Receivables-only replacement; original acceptance unrecovered. Invoice Show Items/Payments and customer embedded invoice history excluded. If separately authorized later, embedded tables must be server-side.
- next: DONE; no changes to excluded embedded tables.

### t_39d87109 — [t_a872a995] REVIEWER DATATABLE P1 C: final Inventory operational tables

- legacy_id: `t_a872a995`
- source_status: `scheduled`; omp_status: `done` (user-approved replacement contract); phase: `datatable-c-replacement`
- predecessor: `t_43e33f86`; successor: tidak ada
- scope/acceptance: approved C replacement matrix; `InventoryCFinalReview` APPROVED; original rubric unrecovered; see DataTable C replacement closure (2026-10-07).
- next: DONE; DataTable C and D are closed; E and Reporting remain held.

### t_3b241cec — [t_9ead5aae] REVIEWER DATATABLE P1 D: final Ticket/SPK/Network Asset

- legacy_id: `t_9ead5aae`
- source_status: `scheduled`; omp_status: `done` (user-approved replacement contract); phase: `datatable-d-replacement`
- predecessor: `t_0f7d528b`; successor: tidak ada
- scope/acceptance: user-approved D matrix; `DFinalReview` APPROVED after QA PASS; original rubric unrecovered; see DataTable D replacement closure (2026-10-07).
- next: DONE; stop at D boundary; E and Reporting held.

### t_3c2d67a9 — [t_27017017] REVIEWER P1: final role persona dan separation-of-duties

- legacy_id: `t_27017017`
- source_status: `scheduled`; omp_status: `done`; phase: `role-persona-sod-replacement`
- predecessor: `t_81eccd66`; successor: tidak ada
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/role-persona-sod/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_3cac1cdf — [t_6b5db45a] TESTER P2: report export dan drill-down

- legacy_id: `t_6b5db45a`
- source_status: `scheduled`; omp_status: `done`; phase: `p2-export-drilldown-replacement`
- predecessor: `t_9de05904`; successor: `t_a51e7983`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/p2-export-drilldown/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_3cfaed65 — [t_2c6f3f6c] TESTER P1: Notification Center persistent

- legacy_id: `t_2c6f3f6c`
- source_status: `scheduled`; omp_status: `done`; phase: `notification-center-replacement`
- predecessor: `t_d1939e94`; successor: `t_298aaec0`
- scope/acceptance: bounded replacement Notification matrix verified by isolated safety+Notification 7/148 and final owned canonical 1/1 (17.3s, exit 0, no teardown warning). UI response projection is distinguished from real backend off-page regression; original rubric remains unrecovered.
- next: independent QA PASS and reviewer APPROVED; phase complete. Focused PHP 15/175 and app deployment/health are recorded above; no fresh full-suite claim.

### t_3d697b14 — [t_079f8070] REVIEWER: customer-sales-attribution-v1 dasar

- legacy_id: `t_079f8070`
- source_status: `todo`; omp_status: `done`; phase: `sales-attribution-base-replacement`
- predecessor: `t_60912019`; successor: tidak ada
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/sales-attribution-base/spec.md`; original acceptance unrecovered.
- next: DONE; see Sales attribution base replacement closure (2026-10-10).

### t_3e41cec6 — [t_14a51272] TESTER Head Company: hierarchy, isolation, switcher dan report reconciliation

- legacy_id: `t_14a51272`
- source_status: `todo`; omp_status: `done`; phase: `head-company-replacement`
- predecessor: `t_25322033`, `t_c2a626e0`; successor: `t_521274c4`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/head-company/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-11.

### t_40d41840 — [t_da35c00e] UI DATATABLE D: Ticket, SPK, Network Asset

- legacy_id: `t_da35c00e`
- source_status: `scheduled`; omp_status: `done` (user-approved replacement contract); phase: `datatable-d-replacement`
- predecessor: `t_69dd2c1a`; successor: `t_1b37b49b`
- scope/acceptance: canonical UI cutover for all three D pages; see DataTable D replacement closure (2026-10-07).
- next: DONE; tester, QA, and reviewer PASS/APPROVED.

### t_40d53598 — [t_7830eec9] BACKEND REPORTING: KPI sales customer-sales-attribution-v1

- legacy_id: `t_7830eec9`
- source_status: `scheduled`; omp_status: `done`; phase: `kpi-sales-replacement`
- predecessor: tidak ada; successor: `t_c4b6971b`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/kpi-sales/spec.md`; original acceptance unrecovered.
- next: DONE; see KPI sales replacement closure (2026-10-10).

### t_40dc90eb — [t_4ce508c1] REVIEWER P2: final persona dashboard dan exception queues

- legacy_id: `t_4ce508c1`
- source_status: `scheduled`; omp_status: `done`; phase: `p2-persona-dashboard-replacement`
- predecessor: `t_09021a86`; successor: tidak ada
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/p2-persona-dashboard/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_43e33f86 — [t_b4173ec8] QA-ISP DATATABLE P1 C: Inventory operational tables

- legacy_id: `t_b4173ec8`
- source_status: `scheduled`; omp_status: `done` (user-approved replacement contract); phase: `datatable-c-replacement`
- predecessor: `t_ee9c7127`; successor: `t_39d87109`
- scope/acceptance: approved C replacement matrix; `InventoryCIndependentQA` PASS; original rubric unrecovered; see DataTable C replacement closure (2026-10-07).
- next: DONE; reviewer APPROVED.

### t_488f0166 — [t_53edd141] P1 DATATABLE BACKEND A: Admin, Customer, HR contract parity

- legacy_id: `t_53edd141`
- source_status: `scheduled`; omp_status: `done` (kontrak pengganti disetujui pengguna); phase: `datatable-ab-replacement`
- predecessor: tidak ada; successor: `t_b0a379d6`
- scope/acceptance: backend A (Users, Roles, Permissions, Customers, Organizations, Employees) — see closure section; rubric asli tidak pulih
- next: DONE; lihat "DataTable A+B replacement closure (2026-10-03)".

### t_4bfa50a0 — [t_8e4da71a] TESTER DATATABLE P1 A+B: Admin/Customer/HR/Billing/Service

- legacy_id: `t_8e4da71a`
- source_status: `scheduled`; omp_status: `done` (kontrak pengganti disetujui pengguna); phase: `datatable-ab-replacement`
- predecessor: `t_b0a379d6`, `t_d3fe1a15`; successor: `t_8d234af5`
- scope/acceptance: backend 125/1482 + canonical E2E 11/11 pada `inbils_test` — see closure section; rubric asli tidak pulih
- next: DONE; lihat "DataTable A+B replacement closure (2026-10-03)".

### t_521274c4 — [t_188f9b7e] QA-ISP Head Company: formal tenant, RBAC dan data-integrity gate

- legacy_id: `t_188f9b7e`
- source_status: `scheduled`; omp_status: `done`; phase: `head-company-replacement`
- predecessor: `t_3e41cec6`; successor: `t_2aa65b96`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/head-company/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-11.

### t_54d82ff9 — [t_0f908b50] REVIEWER: KPI reporting customer-sales-attribution-v1

- legacy_id: `t_0f908b50`
- source_status: `todo`; omp_status: `done`; phase: `kpi-sales-replacement`
- predecessor: `t_58229ddb`; successor: tidak ada
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/kpi-sales/spec.md`; original acceptance unrecovered.
- next: DONE; see KPI sales replacement closure (2026-10-10).

### t_57536305 — OPS LOCATION FRESH8A: one-shot build after same-node listener fix

- legacy_id: tidak tersedia
- source_status: `todo`; omp_status: `done`; phase: `location-map`
- predecessor: `t_e0f1c27b`; successor: `t_fce1ad69`
- scope/acceptance: snapshot tasks[t_57536305].body; evidence: single local build PASS, fresh manifest/chunk and semantic anchor proven.
- next: canonical tester `t_fce1ad69`.

### t_58229ddb — [t_e1b75378] QA-ISP: KPI reporting customer-sales-attribution-v1

- legacy_id: `t_e1b75378`
- source_status: `scheduled`; omp_status: `done`; phase: `kpi-sales-replacement`
- predecessor: `t_6c27c12e`; successor: `t_54d82ff9`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/kpi-sales/spec.md`; original acceptance unrecovered.
- next: DONE; see KPI sales replacement closure (2026-10-10).

### t_594beec6 — [t_491d8cb9] P1 BACKEND: role persona dan separation-of-duties capability bundles

- legacy_id: `t_491d8cb9`
- source_status: `scheduled`; omp_status: `done`; phase: `role-persona-sod-replacement`
- predecessor: tidak ada; successor: `t_f4e0d462`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/role-persona-sod/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_60912019 — [t_609ba86f] QA-ISP: customer-sales-attribution-v1 dasar

- legacy_id: `t_609ba86f`
- source_status: `scheduled`; omp_status: `done`; phase: `sales-attribution-base-replacement`
- predecessor: `t_b70b570d`; successor: `t_3d697b14`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/sales-attribution-base/spec.md`; original acceptance unrecovered.
- next: DONE; see Sales attribution base replacement closure (2026-10-10).

### t_63055b4e — [t_377a1b09] P2 BACKEND: report export parity dan drill-down source rows

- legacy_id: `t_377a1b09`
- source_status: `scheduled`; omp_status: `done`; phase: `p2-export-drilldown-replacement`
- predecessor: `t_a71f5680`; successor: `t_9de05904`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/p2-export-drilldown/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_630f787c — [t_145ef46a] P1 DATATABLE BACKEND B: Billing dan Service contract parity

- legacy_id: `t_145ef46a`
- source_status: `scheduled`; omp_status: `done` (kontrak pengganti disetujui pengguna); phase: `datatable-ab-replacement`
- predecessor: tidak ada; successor: `t_d3fe1a15`
- scope/acceptance: backend B (Invoices, Service Packages, Bandwidth Profiles, Speed Profiles, SLA Tiers) — see closure section; rubric asli tidak pulih
- next: DONE; lihat "DataTable A+B replacement closure (2026-10-03)".

### t_69dd2c1a — [t_5d60f54d] P1 DATATABLE BACKEND D: Ticket, SPK, Network Asset operational tables

- legacy_id: `t_5d60f54d`
- source_status: `scheduled`; omp_status: `done` (user-approved replacement contract); phase: `datatable-d-replacement`
- predecessor: tidak ada; successor: `t_40d41840`
- scope/acceptance: D backend replacement matrix, Eloquent-only and existing exports; see DataTable D replacement closure (2026-10-07).
- next: DONE; all D gates closed, E and Reporting held.

### t_6c27c12e — [t_07542a84] TESTER: KPI reporting customer-sales-attribution-v1

- legacy_id: `t_07542a84`
- source_status: `todo`; omp_status: `done`; phase: `kpi-sales-replacement`
- predecessor: `t_c4b6971b`; successor: `t_58229ddb`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/kpi-sales/spec.md`; original acceptance unrecovered.
- next: DONE; see KPI sales replacement closure (2026-10-10).

### t_77adedd5 — [t_27cd7b68] REVIEWER P1 IA: final target sidebar capability-safe

- legacy_id: `t_27cd7b68`
- source_status: `scheduled`; omp_status: `done`; phase: `sidebar-ia-replacement`
- predecessor: `t_c969a55f`; successor: tidak ada
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/sidebar-ia/spec.md`; original acceptance unrecovered.
- next: DONE; see Sidebar IA replacement closure (2026-10-10).

### t_81b959f1 — [t_17868fdc] P1 UI IA: target sidebar maksimum 7 grup dan navigation capability-safe

- legacy_id: `t_17868fdc`
- source_status: `scheduled`; omp_status: `done`; phase: `sidebar-ia-replacement`
- predecessor: tidak ada; successor: `t_f106f2e8`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/sidebar-ia/spec.md`; original acceptance unrecovered.
- next: DONE; see Sidebar IA replacement closure (2026-10-10).

### t_81eccd66 — [t_b75b5af6] QA-ISP P1: role persona dan separation-of-duties

- legacy_id: `t_b75b5af6`
- source_status: `scheduled`; omp_status: `done`; phase: `role-persona-sod-replacement`
- predecessor: `t_f4e0d462`; successor: `t_3c2d67a9`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/role-persona-sod/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_843a2fb0 — [t_4557c733] REVIEWER P2: final report export dan drill-down

- legacy_id: `t_4557c733`
- source_status: `scheduled`; omp_status: `done`; phase: `p2-export-drilldown-replacement`
- predecessor: `t_a51e7983`; successor: tidak ada
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/p2-export-drilldown/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_87405120 — MICROBATCH TP-UI1: self/team Technician report page

- legacy_id: tidak tersedia
- source_status: `blocked`; omp_status: `superseded`; phase: `technician-performance`
- predecessor: `t_247addc6`; successor: tidak ada
- scope/acceptance: snapshot tasks[t_87405120].body; evidence: historical source only, belum PASS OMP
- next: Replacement `t_b51debaa`; bukan PASS.

### t_8aa097cc — [t_eecc2b24] TESTER REPORTING V2: formula, capability, data dictionary

- legacy_id: `t_eecc2b24`
- source_status: `scheduled`; omp_status: `done`; phase: `reporting-v2-replacement`
- predecessor: `t_d698fabf`; successor: `t_ad34cbf5`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/reporting-v2/spec.md`; original acceptance unrecovered.
- next: DONE; see Reporting V2 replacement closure (2026-10-10).

### t_8d234af5 — [t_e77c6519] QA-ISP DATATABLE P1 A+B: Admin/Customer/HR/Billing/Service

- legacy_id: `t_e77c6519`
- source_status: `scheduled`; omp_status: `done` (kontrak pengganti disetujui pengguna); phase: `datatable-ab-replacement`
- predecessor: `t_4bfa50a0`; successor: `t_a80ca6a1`
- scope/acceptance: independent `DataTableABQA` PASS, no blocking findings — see closure section; rubric asli tidak pulih
- next: DONE; lihat "DataTable A+B replacement closure (2026-10-03)".

### t_97ad905e — [t_b7d387fe] P1 BACKEND: Notification Center persistent dan tenant-safe

- legacy_id: `t_b7d387fe`
- source_status: `scheduled`; omp_status: `done`; phase: `notification-center-replacement`
- predecessor: tidak ada; successor: `t_d1939e94`
- scope/acceptance: approved replacement actor/company-owned inbox, pagination, idempotent reads, payload/link safety and real producer; preserve actor-scoped global unread count. Focused isolated safety+Notification 7/148 PASS including failing-before/passing-after off-page regression; existing implementation retained, original rubric unrecovered.
- next: QA PASS and reviewer APPROVED; phase complete. Adjacent soft-deleted ticket-code fix has canonical proof and final focused PHP 15/175 evidence above.

### t_9bc28da4 — [t_c0741bff] QA-ISP DATATABLE P1 E: Receivables dan Invoice history

- legacy_id: `t_c0741bff`
- source_status: `scheduled`; omp_status: `done` (user-approved replacement contract); phase: `datatable-e-replacement`
- predecessor: `t_333772ab`; successor: `t_9df467ae`
- scope/acceptance: Receivables-only replacement; original acceptance unrecovered. Invoice Show Items/Payments and customer embedded invoice history excluded. Focused backend 8/131 PASS, canonical E2E 4/4 PASS; QA recheck PASS and reviewer APPROVED. See DataTable E replacement closure (2026-10-07).
- next: DONE; E closed. Reporting remains held.

### t_9de05904 — [t_b08b0d54] UI P2: report export, drill-down dan reconciliation links

- legacy_id: `t_b08b0d54`
- source_status: `scheduled`; omp_status: `done`; phase: `p2-export-drilldown-replacement`
- predecessor: `t_63055b4e`; successor: `t_3cac1cdf`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/p2-export-drilldown/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_9df467ae — [t_16b2cc07] REVIEWER DATATABLE P1 E: final Receivables dan Invoice history

- legacy_id: `t_16b2cc07`
- source_status: `scheduled`; omp_status: `done` (user-approved replacement contract); phase: `datatable-e-replacement`
- predecessor: `t_9bc28da4`; successor: tidak ada
- scope/acceptance: Receivables-only replacement; original acceptance unrecovered. Embedded tables and Reporting excluded. QA recheck PASS and reviewer APPROVED; see DataTable E replacement closure (2026-10-07).
- next: DONE; E closed at the approved boundary; Reporting remains held.

### t_9e203f31 — [t_bc11d344] REVIEWER REPORTING P1: final filters canonical dan Stock Card

- legacy_id: `t_bc11d344`
- source_status: `scheduled`; omp_status: `done`; phase: `reporting-p1-replacement`
- predecessor: `t_ecc8a59f`; successor: tidak ada
- scope/acceptance: approved Reporting replacement contract; original acceptance unrecovered. Independent final review of the six-report scope and boundaries; do not mark done without completed prior gates.
- next: DONE; reviewer APPROVED after recheck; see Reporting P1 replacement closure (2026-10-07).

### t_a32e031e — [t_02c3f433] REVIEWER REPORTING V2: final formula, capability, data dictionary

- legacy_id: `t_02c3f433`
- source_status: `todo`; omp_status: `done`; phase: `reporting-v2-replacement`
- predecessor: `t_ad34cbf5`; successor: tidak ada
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/reporting-v2/spec.md`; original acceptance unrecovered.
- next: DONE; see Reporting V2 replacement closure (2026-10-10).

### t_a51e7983 — [t_5231c581] QA-ISP P2: report export dan drill-down

- legacy_id: `t_5231c581`
- source_status: `scheduled`; omp_status: `done`; phase: `p2-export-drilldown-replacement`
- predecessor: `t_3cac1cdf`; successor: `t_843a2fb0`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/p2-export-drilldown/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_a5536496 — [t_724f6b3b] P2 TODO: persona dashboard dan exception queues

- legacy_id: `t_724f6b3b`
- source_status: `scheduled`; omp_status: `done`; phase: `p2-persona-dashboard-replacement`
- predecessor: tidak ada; successor: `t_0f437faa`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/p2-persona-dashboard/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_a71f5680 — [t_8fd13334] P2 TODO: export parity dan drill-down report permission-safe

- legacy_id: `t_8fd13334`
- source_status: `scheduled`; omp_status: `done`; phase: `p2-export-drilldown-replacement`
- predecessor: tidak ada; successor: `t_63055b4e`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/p2-export-drilldown/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_a7a1bda9 — [t_8b306069] TESTER REPORTING P1: filters canonical dan Stock Card reconciliation

- legacy_id: `t_8b306069`
- source_status: `scheduled`; omp_status: `done`; phase: `reporting-p1-replacement`
- predecessor: `t_fefb9a68`; successor: `t_ecc8a59f`
- scope/acceptance: test the approved six-report replacement contract while preserving current filters, formulas, and visuals; Stock Card movement-ledger server pagination/sorting and Audit Log Eloquent empty-scope guard only. No opening balance/reconciliation, aggregate changes, exports, or Technician Performance changes. Original acceptance unrecovered.
- next: DONE; backend 42/547 and E2E 1/1 PASS on `inbils_test`; see Reporting P1 replacement closure (2026-10-07).

### t_a80ca6a1 — [t_99a2f73b] REVIEWER DATATABLE P1 A+B: final Admin/Customer/HR/Billing/Service

- legacy_id: `t_99a2f73b`
- source_status: `scheduled`; omp_status: `done` (kontrak pengganti disetujui pengguna); phase: `datatable-ab-replacement`
- predecessor: `t_8d234af5`; successor: tidak ada
- scope/acceptance: `DataTableABReview` APPROVED, no blockers, 2 advisories (lsp.json gitignored; can.create/Edit label) — see closure section; rubric asli tidak pulih
- next: DONE; lihat "DataTable A+B replacement closure (2026-10-03)".

### t_ad34cbf5 — [t_f84d4a93] QA-ISP REPORTING V2: formula, capability, data dictionary

- legacy_id: `t_f84d4a93`
- source_status: `scheduled`; omp_status: `done`; phase: `reporting-v2-replacement`
- predecessor: `t_8aa097cc`; successor: `t_a32e031e`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/reporting-v2/spec.md`; original acceptance unrecovered.
- next: DONE; see Reporting V2 replacement closure (2026-10-10).

### t_ae471280 — [t_4b7e2421] BACKEND: customer-sales-attribution-v1 dasar

- legacy_id: `t_4b7e2421`
- source_status: `scheduled`; omp_status: `done`; phase: `sales-attribution-base-replacement`
- predecessor: tidak ada; successor: `t_ffe9fc13`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/sales-attribution-base/spec.md`; original acceptance unrecovered.
- next: DONE; see Sales attribution base replacement closure (2026-10-10).

### t_b0a379d6 — [t_6de309d5] UI DATATABLE A: Admin, Customer, HR canonical server-side

- legacy_id: `t_6de309d5`
- source_status: `scheduled`; omp_status: `done` (kontrak pengganti disetujui pengguna); phase: `datatable-ab-replacement`
- predecessor: `t_488f0166`; successor: `t_4bfa50a0`
- scope/acceptance: UI A — Employees canonical cutover, Organizations per-page dedupe, Invoices toPagination, aria-labels — see closure section; rubric asli tidak pulih
- next: DONE; lihat "DataTable A+B replacement closure (2026-10-03)".

### t_b18f253d — [t_9e1e9a30] UI: tambahkan status loading dan progress yang informatif di seluruh halaman

- legacy_id: `t_9e1e9a30`
- source_status: `scheduled`; omp_status: `done`; phase: `loading-progress-replacement`
- predecessor: tidak ada; successor: `t_b3c6843d`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/loading-progress/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_b3c6843d — [t_fabe87ae] TESTER UI: loading dan progress informatif seluruh halaman

- legacy_id: `t_fabe87ae`
- source_status: `scheduled`; omp_status: `done`; phase: `loading-progress-replacement`
- predecessor: `t_b18f253d`; successor: `t_e161eb78`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/loading-progress/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_b51debaa — Implement bounded self/team technician report behavior

- legacy_id: tidak tersedia
- source_status: `triage`; omp_status: `done`; phase: `technician-performance`
- predecessor: `t_247addc6`; successor: `t_c2426c71`
- scope/acceptance: snapshot tasks[t_b51debaa].body; evidence: changed Technician.tsx only; focused ESLint and typecheck PASS. Browser gate pending.
- next: `t_c2426c71` capability navigation.

### t_b64f8b75 — REVIEWER LOCATION FRESH7M: definitive approval

- legacy_id: tidak tersedia
- source_status: `todo`; omp_status: `done`; phase: `location-map`
- predecessor: `t_f5ff6c39`; successor: tidak ada
- scope/acceptance: snapshot tasks[t_b64f8b75].body; evidence: independent `LocationFinalReview` APPROVED, QA PASS, canonical 2/2 and actual browser/runtime evidence complete.
- next: stop at Location phase boundary; recover next phase contract before implementation.

### t_b70b570d — [t_4a56f865] TESTER: integrasi dan keamanan customer-sales-attribution-v1

- legacy_id: `t_4a56f865`
- source_status: `scheduled`; omp_status: `done`; phase: `sales-attribution-base-replacement`
- predecessor: `t_ffe9fc13`; successor: `t_60912019`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/sales-attribution-base/spec.md`; original acceptance unrecovered.
- next: DONE; see Sales attribution base replacement closure (2026-10-10).

### t_bc0fba41 — TESTER TECHNICIAN PERFORMANCE: tenant RBAC formulas and canonical UI

- legacy_id: tidak tersedia
- source_status: `todo`; omp_status: `done`; phase: `technician-performance`
- predecessor: `t_24cae357`; successor: `t_051e9897` (in progress)
- scope/acceptance: snapshot tasks[t_bc0fba41].body; evidence: full isolated PHPUnit 759/5512 green, focused Technician gates PASS, canonical E2E PASS, build PASS, ESLint 0 errors, PHPStan clean (details above).
- next: QA `t_051e9897` then reviewer `t_da288dbd`; stop at the phase gate and do not execute Location.

### t_c2426c71 — MICROBATCH TP-UI2: capability-safe report navigation

- legacy_id: tidak tersedia
- source_status: `todo`; omp_status: `done`; phase: `technician-performance`
- predecessor: `t_b51debaa`; successor: `t_24cae357`
- scope/acceptance: snapshot tasks[t_c2426c71].body; evidence: focused typecheck and ESLint PASS, browser gate pending.
- next: `t_24cae357` canonical E2E.

### t_c2a626e0 — [t_224483db] UI Head Company: company switcher dan consolidated report context

- legacy_id: `t_224483db`
- source_status: `scheduled`; omp_status: `done`; phase: `head-company-replacement`
- predecessor: `t_2cd5c5cc`; successor: `t_3e41cec6`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/head-company/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-11.

### t_c4b6971b — [t_3b435d95] UI REPORTING: KPI sales customer-sales-attribution-v1

- legacy_id: `t_3b435d95`
- source_status: `scheduled`; omp_status: `done`; phase: `kpi-sales-replacement`
- predecessor: `t_40d53598`; successor: `t_6c27c12e`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/kpi-sales/spec.md`; original acceptance unrecovered.
- next: DONE; see KPI sales replacement closure (2026-10-10).

### t_c969a55f — [t_934341cd] QA-ISP P1 IA: target sidebar capability-safe

- legacy_id: `t_934341cd`
- source_status: `scheduled`; omp_status: `done`; phase: `sidebar-ia-replacement`
- predecessor: `t_f106f2e8`; successor: `t_77adedd5`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/sidebar-ia/spec.md`; original acceptance unrecovered.
- next: DONE; see Sidebar IA replacement closure (2026-10-10).

### t_d1939e94 — [t_694adbf6] UI Notification Center: inbox, unread badge, dan deep-link aman

- legacy_id: `t_694adbf6`
- source_status: `scheduled`; omp_status: `done`; phase: `notification-center-replacement`
- predecessor: `t_97ad905e`; successor: `t_3cfaed65`
- scope/acceptance: approved replacement inbox/badge/read/link behavior; mark-all uses global count and unread detail waits for read success. Final canonical 1/1 verifies held-read ordering, already-read navigation, live 403/deleted null links, keyboard and desktop/mobile light/dark; manual recipient320 and staff empty checks recorded above. Original rubric remains unrecovered.
- next: independent QA PASS and reviewer APPROVED; app deployment/health done. Phase complete under the replacement contract, stop at boundary.

### t_d3fe1a15 — [t_8a26e182] UI DATATABLE B: Billing dan Service canonical server-side

- legacy_id: `t_8a26e182`
- source_status: `scheduled`; omp_status: `done` (kontrak pengganti disetujui pengguna); phase: `datatable-ab-replacement`
- predecessor: `t_630f787c`; successor: `t_4bfa50a0`
- scope/acceptance: UI B — Service sortKey/fields aligned to SORTABLE + Resources — see closure section; rubric asli tidak pulih
- next: DONE; lihat "DataTable A+B replacement closure (2026-10-03)".

### t_d698fabf — [t_f21477eb] UI REPORTING V2: definisi KPI, granular capability, dan data dictionary

- legacy_id: `t_f21477eb`
- source_status: `scheduled`; omp_status: `done`; phase: `reporting-v2-replacement`
- predecessor: `t_1d565698`; successor: `t_8aa097cc`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/reporting-v2/spec.md`; original acceptance unrecovered.
- next: DONE; see Reporting V2 replacement closure (2026-10-10).

### t_d8015197 — [t_310aa47f] QA-ISP REMEDIASI: release gate Dispatch/My Work

- legacy_id: `t_310aa47f`
- source_status: `scheduled`; omp_status: `done`; phase: `dispatch-my-work-replacement-matrix`
- predecessor: `t_966400a1`; successor: `t_333b33e2`
- scope/acceptance: explicit user-approved replacement matrix above; fresh canonical 13/13 and backend 44/439; independent `DispatchMatrixQa` PASS with teardown caveat recorded.
- next: final reviewer `t_333b33e2` under replacement scope.

### t_da288dbd — REVIEWER TECHNICIAN PERFORMANCE: final technical approval

- legacy_id: tidak tersedia
- source_status: `todo`; omp_status: `done`; phase: `technician-performance`
- predecessor: `t_051e9897`; successor: `t_e0f1c27b` (phase gate)
- scope/acceptance: snapshot tasks[t_da288dbd].body; evidence: independent final APPROVED, QA and executed verification complete (above).
- next: Stop at phase boundary; no Location implementation or Hermes mutation in this phase.

### t_dcf232f5 — [t_2fb912de] P1 DATATABLE BACKEND C: Inventory operational tables

- legacy_id: `t_2fb912de`
- source_status: `scheduled`; omp_status: `done` (user-approved replacement contract); phase: `datatable-c-replacement`
- predecessor: tidak ada; successor: `t_23e46c02`
- scope/acceptance: Inventory backend ordering/page-size/export parity and Eloquent low-stock behavior under approved C matrix; original rubric unrecovered; see DataTable C replacement closure (2026-10-07).
- next: DONE; QA PASS and reviewer APPROVED.

### t_e0f1c27b — FIX LOCATION FRESH8A: stop same-node Leaflet map listener after saved marker click

- legacy_id: tidak tersedia
- source_status: `todo`; omp_status: `done`; phase: `location-map`
- predecessor: `t_da288dbd`; successor: `t_57536305`
- scope/acceptance: snapshot tasks[t_e0f1c27b].body; evidence: one-call event termination fix, focused ESLint and tsc PASS. OMP provenance per cutover contract.
- next: build PASS; tester active.

### t_e161eb78 — [t_9c572a3c] QA-ISP UI: loading dan progress informatif

- legacy_id: `t_9c572a3c`
- source_status: `scheduled`; omp_status: `done`; phase: `loading-progress-replacement`
- predecessor: `t_b3c6843d`; successor: `t_02ec133d`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/loading-progress/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_e7cc36eb — [t_ceff6502] REVIEWER P1: final Notification Center

- legacy_id: `t_ceff6502`
- source_status: `scheduled`; omp_status: `done`; phase: `notification-center-replacement`
- predecessor: `t_298aaec0`; successor: tidak ada
- scope/acceptance: independent `NotificationFinalReview` APPROVED the explicitly user-authorized Notification replacement contract; findings empty, confidence 0.95. QA PASS and final focused PHP/canonical/deployment evidence accepted; original rubric remains unrecovered.
- next: stop at Notification phase boundary. DataTable recovery assessment is next after user gate; implementation is not authorized.

### t_ecc8a59f — [t_4c29e77d] QA-ISP REPORTING P1: filters canonical dan Stock Card

- legacy_id: `t_4c29e77d`
- source_status: `scheduled`; omp_status: `done`; phase: `reporting-p1-replacement`
- predecessor: `t_a7a1bda9`; successor: `t_9e203f31`
- scope/acceptance: approved Reporting replacement contract; original acceptance unrecovered. Independent QA-ISP PASS with no findings.
- next: DONE; see Reporting P1 replacement closure (2026-10-07).

### t_ee9c7127 — [t_bdd2f6f9] TESTER DATATABLE P1 C: Inventory operational tables

- legacy_id: `t_bdd2f6f9`
- source_status: `scheduled`; omp_status: `done` (user-approved replacement contract); phase: `datatable-c-replacement`
- predecessor: `t_23e46c02`; successor: `t_43e33f86`
- scope/acceptance: approved C replacement matrix; Inventory 65/773, focused Stock 7/394 and canonical browser 9/9 PASS with limits in DataTable C replacement closure (2026-10-07); original rubric unrecovered.
- next: DONE; QA PASS and reviewer APPROVED.

### t_ef68d35a — [t_094ba6df] P1 DATATABLE BACKEND E: Receivables dan Invoice embedded history

- source_status: `scheduled`; omp_status: `done` (user-approved replacement contract); phase: `datatable-e-replacement`
- predecessor: tidak ada; successor: `t_353135bf`
- scope/acceptance: Receivables index only; Eloquent-only, no export. Original acceptance unrecovered; Invoice Show Items/Payments and customer embedded invoice history excluded. Focused backend 8/131 and Billing 72/456 PASS; see DataTable E replacement closure (2026-10-07).
- next: DONE; E closed. Any separately authorized embedded tables must be server-side.

### t_f106f2e8 — [t_7a8653a0] TESTER P1 IA: target sidebar dan navigation capability-safe

- legacy_id: `t_7a8653a0`
- source_status: `scheduled`; omp_status: `done`; phase: `sidebar-ia-replacement`
- predecessor: `t_81b959f1`; successor: `t_c969a55f`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/sidebar-ia/spec.md`; original acceptance unrecovered.
- next: DONE; see Sidebar IA replacement closure (2026-10-10).

### t_f1c27ea9 — [t_a31bc2c0] UI P2: persona dashboard dan exception queues

- legacy_id: `t_a31bc2c0`
- source_status: `scheduled`; omp_status: `done`; phase: `p2-persona-dashboard-replacement`
- predecessor: `t_0f437faa`; successor: `t_336a4dc4`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/p2-persona-dashboard/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_f4e0d462 — [t_3a02ef92] TESTER P1: role persona dan separation-of-duties

- legacy_id: `t_3a02ef92`
- source_status: `scheduled`; omp_status: `done`; phase: `role-persona-sod-replacement`
- predecessor: `t_594beec6`; successor: `t_81eccd66`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/role-persona-sod/spec.md`; original acceptance unrecovered.
- next: DONE; closed 2026-10-10.

### t_f5ff6c39 — QA-ISP LOCATION FRESH7M: independent final audit

- legacy_id: tidak tersedia
- source_status: `todo`; omp_status: `done`; phase: `location-map`
- predecessor: `t_fce1ad69`; successor: `t_b64f8b75`
- scope/acceptance: snapshot tasks[t_f5ff6c39].body; evidence: independent `LocationQa` PASS, no actionable findings. Reviewed source ownership, cleanup, tenant/data safety and executed browser evidence; no DB/test/build reruns.
- next: final reviewer `t_b64f8b75`.

### t_fce1ad69 — TESTER LOCATION FRESH8A: canonical saved popup no-draft 2/2

- legacy_id: tidak tersedia
- source_status: `todo`; omp_status: `done`; phase: `location-map`
- predecessor: `t_57536305`; successor: `t_f5ff6c39`
- scope/acceptance: snapshot tasks[t_fce1ad69].body; evidence: after material focus remedy, canonical 2/2 PASS and manual native-event/no-draft/keyboard proof above.
- next: independent QA `t_f5ff6c39`.

### t_fefb9a68 — [t_9fc90ba0] UI REPORTING P1: filters canonical dan Stock Card reconciliation

- legacy_id: `t_9fc90ba0`
- source_status: `scheduled`; omp_status: `done`; phase: `reporting-p1-replacement`
- predecessor: `t_316be420`; successor: `t_a7a1bda9`
- scope/acceptance: user-approved replacement contract; preserve existing filters, formulas, and visuals. Stock Card is the existing movement ledger with server pagination/sorting only; no opening balance or reconciliation. Audit Log retains canonical DataTable and changes only its raw empty-scope guard to Eloquent. Existing aggregates stay unchanged; no exports or Technician Performance code changes. Original acceptance unrecovered.
- next: DONE; see Reporting P1 replacement closure (2026-10-07).

### t_ffe9fc13 — [t_0d86de36] UI: customer-sales-attribution-v1 pada Customer

- legacy_id: `t_0d86de36`
- source_status: `scheduled`; omp_status: `done`; phase: `sales-attribution-base-replacement`
- predecessor: `t_ae471280`; successor: `t_b70b570d`
- scope/acceptance: owner-approved replacement contract 2026-10-10 (recommended option for every Owner decision) in `.scratch/sales-attribution-base/spec.md`; original acceptance unrecovered.
- next: DONE; see Sales attribution base replacement closure (2026-10-10).


## isp_ari gap lanes (2026-10-11)

Analisis gap vs `/home/madmouse/Documents/web/isp_ari` (laporan scout `/tmp/isp_ari_gap.md`, diverifikasi ke kedua repo). Lane baru, belum ada kartu Hermes/OMP; semua berstatus `needs-owner-approval` sampai Owner menjawab Owner decisions di spec masing-masing. Tidak ada kode yang berubah.

| Lane (`.scratch/<slug>/`) | Tiket | Status | Catatan |
| --- | --- | --- | --- |
| `customer-status-rollup` | 4 (backend, ui, tester, reviewer) | done | Status customer turunan dari subscription (ADR-011 isp_ari); kolom baru `customer_status`, `is_active` tetap manual. |
| `auth-2fa-passkeys` | 6 (2 backend, ui, tester, security-reviewer, passkeys kondisional) | done (TOTP; passkeys deferred) | TOTP + recovery code; butuh persetujuan library (`pragmarx/google2fa`, `bacon/bacon-qr-code`). isp_ari TIDAK menjalankan 2FA/passkey (fitur Fortify mati). |
| `installation-spk-delta` | 0 | no-gap | inbils sudah membuat SPK instalasi otomatis saat onboarding (`CreateCustomerOnboardingAction`); klaim scout "PARTIAL" salah. |
| `midtrans-payment` | 8 (4 backend, ui, tester, security-reviewer, reviewer) | done (sandbox pilot; production blocked; ticket 08 closed by owner on sandbox evidence, public-URL delivery checked at go-live) | Snap sandbox + webhook + portal bayar + rekonsiliasi; bertentangan dengan pengecualian di `ENTERPRISE_ISP_READINESS.md` (Q1). Kunci hanya di `.env`. |
| `isp-roadmap` | 0 | roadmap | Item yang tidak ada di kedua aplikasi (MikroTik/RADIUS, WhatsApp, portal lanjutan, IPAM, ODN, PO/vendor, payroll, GIS). |
| `private-media` | 3 (backend, ui, tester) | done | Temuan keamanan F-01 (medium): lampiran tiket, bukti SPK, dan dokumen customer disimpan di disk `public` dan bisa diunduh tanpa login lewat `/storage/{id}/{file}`. Rekomendasi: disk privat + route berotorisasi + command migrasi file lama. |

Ledger tetap otoritatif; saat Owner menyetujui sebuah lane, tambahkan kartu/entri ledger dan perbarui `Status:` di `.scratch`.

### Owner-approved wave closure (2026-10-12)

- Owner approved (2026-10-11, `ask`): private-media (recommended), ticket close by any `ticket.close` holder on `resolved`, customer tickets without location choice, customer portal account = random password + emailed set-password link, Tailwind 4 deferred, gap lanes `customer-status-rollup`, `auth-2fa-passkeys`, `midtrans-payment` (recommended options). Owner also allowed well-maintained libraries.
- Delivered: private media route + privatize command; TOTP 2FA (pragmarx/google2fa, bacon/bacon-qr-code); customer status rollup; Midtrans Snap sandbox portal + webhook + reconcile; customer access link; ticket close/location rules; auth hardening (inactive login refused, throttled uniform forgot-password, trusted hosts, step-up throttle).
- Security reviews: customer/ticket SIGNED, private-media SIGNED, 2FA SIGNED (medium throttle race fixed), Midtrans BLOCKED (MTP-01 phone password, MTP-03 sandbox on production) -> fixed -> re-review. Findings fixed red-first.
- Deploy: `migrate --force`, `db:seed --class=RolePermissionSeeder --force`, `inbils:media:privatize --dry-run` then real, `customers:rotate-legacy-passwords --dry-run` then real (queue + mail running), `APP_URL` = public host.

## ISP roadmap lanes (2026-10-12)

Owner picked from `.scratch/isp-roadmap/spec.md`; specs written by architect. Owner approved every recommended option (2026-10-12/13); statuses reconciled 2026-10-12 against `.scratch` tickets.

| Lane (`.scratch/<slug>/`) | Tiket | Status | Catatan |
| --- | --- | --- | --- |
| `mikrotik-radius` | 9 | superseded by `freeradius` | Ticket 01 (auto-isolir, reconnect on payment) and the isolir page stay; RouterOS API provisioning removed. |
| `freeradius` | 7 | done | FreeRADIUS SQL + RFC 5176 Disconnect, block/unblock; lab E2E 4/4. Production `RADIUS_ENABLED=false` until the owner installs `inbils_realm` and moves routers. |
| `whatsapp-notifications` | 9 | done | WhatsApp Cloud API resmi, consent, quiet hours, kuota per company. Live delivery waits on Meta Business verification, approved templates and host credentials. |
| `ipam-odn` | 8 | done | IPAM (`Modules/Ipam`, mlocati/ip-lib) + ODN port register; security review APPROVED 2026-10-12 (ticket 08). |
| `mobile-api` | 9 | done | Sanctum `/api/v1` for the two apps; security review APPROVED. |
| `flutter-customer` / `flutter-technician` | 8 / 11 | done except release (08 / 10) | Play Store release waits on the owner's signing key and a public HTTPS host. Repo `madmouse17/inbils-mobile`. |

Urutan tabrakan file saat lane berjalan (historis): `SubscriptionService.php`, `BillingService::recordPayment`, `routes/console.php`, `RolePermissionSeeder.php`, `navigation.ts` disentuh lebih dari satu lane, jadi lane tersebut dikerjakan berurutan (ipam-odn 01 → mikrotik-radius → whatsapp; kemudian freeradius menggantikan mikrotik-radius).

