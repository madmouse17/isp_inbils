# MOBILE: customer Home hero, quick actions, bill states, connection, restyled screens

Status: ready-for-agent
Role: mobile
Blocked by: 03; 01 for branding/connection fields
Spec: `../spec.md`

## Scope (owned files, repo `inbils-mobile/customer`)

Home per spec M4/V3/V4/V8 (hero per subscription with page dots when several, quick actions with Hubungi ISP via `url_launcher` tel/WhatsApp, Sedang berjalan section, slots for announcements/carousel left out until `announcements-promo` lands); invoices list/detail with amount hierarchy and state chips; subscriptions list/detail with package, address, billing day, contract; notifications; Akun with theme mode and ISP contact card; bottom tabs Beranda/Tagihan/Bantuan/Akun; launcher icon + splash (V9, dev deps). Models updated for the new `me.company` and connection fields. Folded in from `package-categories` 04: subscription model gets optional `package.category {id, name}` (tolerate absence), shown as "Kategori" on subscription list and detail. Widget tests per spec (hero states, text scale 2.0).

## Acceptance criteria

- [ ] `flutter analyze` clean; `flutter test` green; screenshots light/dark on the AVD attached to the comment.

## Comments
