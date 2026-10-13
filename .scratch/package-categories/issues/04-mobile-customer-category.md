# MOBILE: customer app shows the package category

Status: superseded
Role: mobile
Blocked by: 02 (API field)
Spec: `../spec.md`

## Scope

Repo `/home/madmouse/Documents/web/inbils-mobile`, `customer/`: subscription model gets optional `package.category {id, name}` (tolerate absence), subscription list and detail show it (Indonesian label "Kategori"), fixtures + widget tests.

## Acceptance criteria

- [ ] `flutter analyze`, `flutter test` green in `customer/`; `flutter build apk --debug --flavor dev` ok.

## Comments

2026-10-14, orchestrator: folded into `.scratch/mobile-redesign/issues/04-mobile-customer-home-and-screens.md` (ledger rule: mobile tickets of other lanes build on the redesigned customer screens). The API field ships in ticket 02 of this lane.
