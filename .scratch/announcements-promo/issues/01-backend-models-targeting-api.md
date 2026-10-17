# BACKEND: models, targeting, web endpoints, customer API

Status: done
Role: backend
Blocked by: package-categories merged; ui-consistency 02/03 merged
Spec: `../spec.md`

## Scope (owned files)

Placement (decided 2026-10-14): module `Modules/Service`. The audience is defined by the Service module's own tables (`service_subscriptions`, `service_packages`, `service_package_categories`) plus the shared-core `locations`, so no other module's model is imported. Follow the module's PSR-4 map (`Modules/Service/composer.json`): models in `Modules/Service/Models`, controllers and FormRequests in `Modules/Service/Http`, policies in `Modules/Service/Policies`, services, queries and resources in `Modules/Service/app/` (next to `app/Services/SlaTierCompliance.php`); do not create `app/Models` classes. The customer API route joins the existing `api.customer` group in `routes/api.php`, registered the same way as the module controllers already there.

- Migrations `announcements`, `promo_banners` with `audience` ∈ {`all`, `targeted`} (explicit, so deleting targets never widens an item to everyone; `targeted` with no remaining target rows matches nobody) and pivot tables named alphabetically with real foreign keys: `announcement_location`, `announcement_service_package_category`, `location_promo_banner`, `promo_banner_service_package_category` (composite primary key; `cascadeOnDelete` from the announcement/banner side, `restrictOnDelete` from the location/category side; locations soft-delete, and a soft-deleted location stays a valid target row).
- Models with `BelongsToCompany`; policies + permission `announcement.manage` (RolePermissionSeeder, `docs/SECURITY.md`).
- FormRequests (B1/B4 validation; target ids validated with `Rule::exists(...)->where('company_id', current)`).
- Web controllers (index/store/update/publish/deactivate/destroy draft).
- `CustomerAudienceQuery` in `Modules/Service/app/Queries` (B2 in SQL: subscriptions → serving POP `locations.path` prefix of a targeted location, packages → targeted category; R4 indexes), with `EXPLAIN` pasted in the ticket comment.
- Customer API (R1, B7) with allow-list resources; media authorization for `promo_banner` images; home-feed cache + invalidation; OpenAPI regenerated; audit (R2). Tests per spec.

## Acceptance criteria

- [x] Focused PHPUnit green incl. the targeting matrix; Pint; OpenAPI contract test.

## Comments

- Web page contract for ticket 03, published before ticket 02 started:
  - `GET /admin/announcements?tab=announcements|banners` renders `Admin/Announcements/Index`.
  - Props: `tab`; paginated `announcements` or `banners`; `counts`; `filters`; `statuses` = `[draft, scheduled, live, ended, inactive]`; `kinds` = `[info, outage, maintenance]`; `audiences` = `[all, targeted]`; `appRoutes` = `[invoices, subscriptions, ticket_create, visits]`; `packageCategories`; optional remote `locationOptions`; `preview` = `{banners, max_banners:5, auto_advance_seconds:5}`; `can.manage`.
  - Announcement row: `{id,title,body,kind,audience,status,starts_at,ends_at,published_at,location_targets,package_category_targets}`. Banner row adds `{alt_text,image_url,link_kind,link_target,sort_order,tap_count}`.
  - Store/update announcement payload: `{title,body,kind,audience,starts_at,ends_at?,location_ids[],service_package_category_ids[]}`. Store/update banner payload: `{title,alt_text,image,link_kind,link_target?,audience,starts_at,ends_at?,sort_order,location_ids[],service_package_category_ids[]}`; multipart updates use `_method=PUT`.
  - Routes: `admin.announcements.{store,update,publish,deactivate,destroy}` and `admin.promo-banners.{store,update,publish,deactivate,destroy}`. Published rows cannot be destroyed.
  - Customer preview data uses the same allow-list shape as `GET /api/v1/customer/home-feed`: `{data:{announcements:[],banners:[]}}`. Banner `image_url` points to authenticated `api.v1.media.show`.
- Audience query `EXPLAIN` (`inbils_test`, targeted location + category): `customers` and `service_subscriptions` use company indexes (`type=ref`); package, POP, and target location use primary-key `eq_ref`; both pivots use composite primary keys; category pivot reports `Using index; FirstMatch(c)`. Estimated rows are `1` for every step.
- 2026-10-16 (Main follow-up): `GET /admin/announcements?tab=…&status=draft|scheduled|live|ended|inactive` filters both lists (unknown value ignored, `filters.status` echoes a valid value only) through `ofStatus`, the SQL twin of `status()` in `HasPublicationStatus`, pinned by `AnnouncementManagementTest::test_status_filter_returns_each_lifecycle_status_on_both_tabs`; the `timezone` prop is gone from the contract (pages read the shared `company.timezone`).
