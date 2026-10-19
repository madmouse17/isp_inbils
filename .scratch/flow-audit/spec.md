# End-to-end flow audit of the ISP operation (report to the owner)

Status: done
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `flow-audit`
Source: owner 2026-10-16: "setelah task ini semua, lakukan pengujian e2e satu persatu flow nya, dari awal sampai akhir, dan audit juga dan catat untuk dilaporkan ke saya adakah kejanggalan atau flow yang perlu diperbaiki, biar lebih smooth dan bagus terkait flow nya sesuai dengan company isp yang ada".

## Rules

- Runs AFTER every open lane is merged (platform-superadmin, push-notifications, mobile-redesign 08/09, opaque-ids).
- **Database: the dev database `inbils` (Sail, http://localhost), never `inbils_test`** (owner 2026-10-16: "lakukan pengujiannya jangan di inbils test tapi langsung di inbils db saja biar saya nanti juga bisa lihat manual sendiri"). FORBIDDEN on `inbils`: `migrate:fresh`, `migrate:refresh`, `db:wipe`, `db:seed` of demo/reset seeders, `RefreshDatabase`/`DatabaseTruncation` traits, `scripts/e2e-setup.mjs`, `tests/e2e/setup.php`, any `TRUNCATE`/`DELETE` cleanup; only `migrate` (additive) is allowed. Nothing is reset or truncated; every flow leaves its records in place, named with the prefix `AUDIT-` or the date so the owner can find them (customer names, SPK titles, ticket titles, announcement titles). The report lists the record codes per flow. Automated suites (PHPUnit/E2E) keep using `inbils_test` and are not part of the audit.
- Each flow is executed by hand (browser via Playwright scripts or the `browser` helper, apps on the AVD), from the first action to the last side effect (notification, audit row, invoice, stock movement), by the persona who does it in a real ISP. Existing E2E specs are not the audit; they are run once as a regression gate.
- **UX lens (owner 2026-10-17: "rekomendasi untuk ux experience lebih baik dan tepat … supaya ui dan ux experience lebih bagus dan tepat serta cepat").** Every flow step is also judged on these questions, and each answer that is "no" becomes a UX finding with a concrete recommendation:
  1. Typing what should be picked: any reference typed by hand (ids, codes, names) instead of chosen from its table with search.
  2. Long selects: any select with > 7 options that is not searchable; any list the user scrolls instead of filtering.
  3. Clicks to the goal: count the clicks and screens from the trigger to the done state; flag anything that takes more than the obvious minimum (e.g. create customer → SPK → assign needs 3 screens today).
  4. Defaults and prefill: fields the system already knows (company default POP, today's date, the only package category, the technician's own employee) but still asks for.
  5. Feedback: after every action, is the result visible where the user looks (toast + updated row) without reload; are errors next to the field in Indonesian; are destructive actions confirmed once, not twice.
  6. Speed: pages > 1 s to interact, tables without server search, filters without debounce, forms losing state on validation error, missing loading/skeleton states.
  7. Consistency: same action named the same everywhere (R6 glossary), same picker component everywhere, same status colours.
  8. Mobile first-run: can a technician or customer finish the core job (SPK visit / pay a bill / report a problem) in under a minute without help.
  9. Keyboard and accessibility: can an operator complete the top 5 web flows by keyboard; focus order; 44 px targets on phones.
  10. Role fit: does the persona see only what they need (no admin noise for staff/technician), and nothing missing for their job.
  Each UX finding records: flow, step, what it is now, what it should be, benefit (clicks/seconds saved, errors avoided), effort (S/M/L), and a mockup note when the change is visual.
- Findings are RECORDED, not fixed, except crashes that block the flow (fixed with a test, listed). Each finding: flow, step, what happened, what an ISP would expect, severity (blocker / friction / polish), suggested fix, owner decision needed (yes/no).
- Output: `docs/plans/FLOW_AUDIT_<date>.md` (Indonesian, for the owner) with one section per flow (defects + UX findings), screenshots under `docs/plans/flow-audit/`, a summary table at the top (defects by severity; UX findings ranked by benefit/effort), and a proposed order of fixes. Presented to the owner as a grilling round (recommendation per finding).

## Flows (in business order)

1. Platform: create ISP tenant → invitation mail → first admin sets password → 2FA → company profile, branding, map default, numbering, settings.
2. Catalogue: bandwidth/speed profiles, SLA tiers, package categories, packages, locations/POP, NAS/RADIUS, ODN.
3. Team: employees, users, roles (read-only), vehicles, documents; technician/customer app-only login.
4. Customer onboarding: create customer with address (map), contacts, subscription with package + POP + drop port → installation SPK auto-created → dispatch/assign → technician app: berangkat → check-in → materials (inventory request → warehouse → issue) → evidence → submit → manager review/approve → network account provisioned → customer activated → first invoice.
5. Billing cycle: invoice generation (period), WhatsApp/push/email reminders, due/overdue, Midtrans payment from the customer app, webhook → paid → receipt; manual payment by staff; receivables; overdue suspension → isolir page → payment → auto reactivation.
6. Support: customer creates ticket from the app ("Ada masalah?" → hint → ticket), SLA deadlines, assignment, technician response/resolution with check-in, comments/attachments both ways, close, evaluation; SLA report.
7. Network ops: outage announcement targeted to a POP → customer notification + Beranda card; maintenance; NAS resync; block/unblock internet (isolir manual); IPAM static IP.
8. Inventory: request from SPK, approval, warehouse pick, issue, return/reject, stock card, movements, adjustments.
9. Reports: business, sales KPI, technician performance, SLA, asset, consolidated (head/subsidiary), audit log, exports (CSV/PDF).
10. Subsidiary + head company: create subsidiary, switch, consolidated report, role rules.
11. Platform support: impersonate (read-only → write), reset 2FA, resend invite, unlock login, suspend tenant → tenant login blocked → reactivate, export, audit.
12. Apps cross-cutting: push notification for every event in `push-notifications` N0/N3, deep links, dark mode, reduce motion, offline (technician queue), language/copy consistency.

## Tickets

01-prepare-fixtures-and-personas · 02-run-flows-01-06 · 03-run-flows-07-12 · 04-report-and-grilling
