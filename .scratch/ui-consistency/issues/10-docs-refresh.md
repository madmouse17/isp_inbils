# DOCS: refresh the three user guides

Status: done
Role: docs
Blocked by: 04, 05, 06, 07, 08, 09
Spec: `../spec.md`

## Scope

Re-run `npm run docs:screenshots` (and the app drives only if app screens changed: they do not in this lane), update `docs/user-guide/web.md` to the new Indonesian labels and controls, remove the caveats whose defects were fixed (R7), add the 47 missing screens listed in the GuideWeb report where they now exist, re-render PDFs.

## Acceptance criteria

- [x] PDFs re-rendered; no caveat left for a fixed defect; every image exists.

## Comments

Carried over from `sla-targets` (2026-10-14): `docs/user-guide/images/web/sla-tiers.png` and `tickets-show.png` show the old English columns/labels; regenerate them with `npm run docs:screenshots` together with this ticket.

2026-10-14, orchestrator: `web.md` refreshed by UiDocs/UiDocs2 (63 sections, R7 caveats removed, 30 new screenshot entries); `npm run docs:screenshots` → 84/84 PNG rendered (1 passed, 1.2m); `inbils:docs:pdf` re-rendered web/teknisi/pelanggan PDFs. Bandwidth/Speed profile controllers translated by the orchestrator during the pass. App screens unchanged in this lane (app images untouched).
