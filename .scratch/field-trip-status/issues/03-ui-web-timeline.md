# UI: SPK timeline and Dispatch badge

Status: done
Role: ui
Blocked by: 01
Spec: `../spec.md`

## Scope (owned files)

`resources/js/Components/composite/WorkOrderTimeline.tsx` (new), `Pages/Admin/SPK/Show.tsx` (use it), Dispatch row badge (F7), status map entry in `lib/statuses/workOrder.ts`; E2E update in `spk-lifecycle`/`dispatch-my-work`.

## Acceptance criteria

- [x] `tsc`, ESLint clean; E2E specs type-check.

## Comments

### 2026-10-16 FieldTripWeb: done

**What changed.**

- `resources/js/Components/composite/WorkOrderTimeline.tsx` (new, exported from `composite/index.ts`): vertical `<ol aria-label="Tahap kunjungan">` of the four server steps. Each step has its own icon (done = `CheckCircle2`, current = `CircleDot` with `aria-current="step"`, pending = `Circle`) and its own words ("Tahap sekarang, ...", "Belum dicapai", sr-only "Selesai"), so colour never carries the state alone. Times use `formatDateTime(at, company)`; a reached step with `at: null` says "waktu tidak tercatat". `installed` is terminal: reaching it draws it as done, never as "now". `rejected` puts the `EnumBadge` "Perlu perbaikan" on the current step (`in_progress`). `cancelled` / `steps: []` replaces the list with "Dibatalkan" and a sentence.
- `Pages/Admin/SPK/Show.tsx`: new props `steps`, `currentStep`; a "Tahap kunjungan" card under "Tindakan" in the right column. Approve / reject / other actions are untouched, same card, same order.
- `lib/statuses/workOrder.ts`: new map `WORK_ORDER_TRIP` (`en_route` "Dalam perjalanan" info, `rework` "Perlu perbaikan" danger). It is a map of its own, not an entry of `WORK_ORDER_STATUS`: SPK Index builds its status filter from `optionsOf(WORK_ORDER_STATUS)` and `WORK_ITEM_STATUS` spreads it, and `en_route` is not a status. Pinned in `statuses.selfcheck.mjs`.
- Dispatch: `DispatchItem.en_route_at?` in `types/index.ts`; `isEnRoute(item)` in `Pages/Admin/Dispatch/components/utils.ts` (`domain === 'spk' && status === 'assigned' && en_route_at != null`); the badge shows under the assignee in the desktop table (`Index.tsx`) and in `MobileDispatchCard.tsx`. My Work rows are unchanged (no `en_route_at` there).
- E2E (type-check only, not run): `spk-lifecycle.spec.ts` asserts four list items and the current step "Ditugaskan" after assign, calls `en-route` before `start`, and asserts no current step and no "Belum dicapai" at `waiting_review`; `dispatch-my-work.spec.ts` sets the dispatched SPK en route through the technician API and expects "Dalam perjalanan" in its Dispatch row.
- Ticket check-in site fix: `Modules/Ticketing/.../Technician/TicketController.php:39` now `FieldCheckIn::site($started->subscription?->installationAddress ?? $started->location)`: the installation address when the subscription has one (an address without coordinates gives `distance_m: null`, never the POP's distance, same rule as `WorkOrderSite`), else the ticket location. `TicketsTest` distance test now checks in near the address (111 m) and a new test pins "address first, never the POP" and the null case.
- `docs/business/spk.md`: `en_route_at` / `submitted_at` columns, the `enRoute` transition and `submitted_at` on submit, a "Tahap kunjungan (timeline)" section (four steps, times, rules for rejected / cancelled, reset on re-assign, Dispatch badge, site rule).

**Evidence.**

- `npx tsc --noEmit` -> 0 errors. `npx eslint` on the touched dirs (`composite`, `Pages/Admin/SPK`, `Pages/Admin/Dispatch`, `lib`, `types/index.ts`) -> "No issues found". `npx prettier --write --ignore-path /dev/null` on touched files: formatted (the repo `.prettierignore` has a stray ` artisan` line that makes plain prettier match nothing here).
- `node resources/js/lib/statuses.selfcheck.mjs` pass 13 / fail 0; `copy.selfcheck.mjs` pass 3; `navigation.selfcheck.mjs` pass 25; `AssignDialog.selfcheck.mjs` pass.
- E2E specs: `tsc --noEmit --skipLibCheck --strict --module esnext --moduleResolution bundler --target es2022 --types node tests/e2e/spk-lifecycle.spec.ts tests/e2e/dispatch-my-work.spec.ts` -> no output (clean).
- `flock /tmp/inbils-e2e.lock env DOCKER_CONTEXT=default ./vendor/bin/sail exec -T -u sail laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact tests/Feature/Api/V1/Technician/TicketsTest.php` -> `Tests: 8 passed (65 assertions)`. `vendor/bin/pint --test` on the two PHP files -> ok.

**Not verified.** No browser render, no E2E run (not allowed). The new ticket test and the fix were written in the same pass, so a red run was not captured.
