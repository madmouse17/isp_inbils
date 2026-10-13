# BACKEND: models, targeting, web endpoints, customer API

Status: ready-for-agent
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

- [ ] Focused PHPUnit green incl. the targeting matrix; Pint; OpenAPI contract test.

## Comments
