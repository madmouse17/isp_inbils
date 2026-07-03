# Database Schema — inbils

> Source of truth for schema conventions. Per-module entity detail:
> `docs/business/*.md`.

## Conventions

- Engine: MySQL 8 (Laragon dev). Charset utf8mb4, collation
  utf8mb4_unicode_ci.
- Every master/transaction table: `company_id FK → companies.id NOT
  NULL` + trait `BelongsToCompany` (global scope, auto-filter per
  company). Exceptions: `companies`, `users` (company_id nullable
  pre-wizard), `roles`, `permissions`, `activity_log` (global spatie).
- Business code (code/number/sku) = unique per company:
  `(company_id, code)`, NOT global unique.
- Soft delete (`deleted_at`) for master + transaction entities with
  lifecycle. NOT soft delete for: audit trail immutable
  (stock_movements, network_asset_installations, work_order_assignments,
  payments, activity_log).
- Timestamps: `created_at`, `updated_at` default. Audit trail tables =
  `created_at` + `updated_at` only (append-only).
- FK constraint: `ON DELETE RESTRICT` for parent master, `ON DELETE
  CASCADE` for child line items.
- Polymorphic reference: `reference_type` (string class name) +
  `reference_id` (bigint unsigned). No DB-level FK constraint, app-level
  validation.
- Decimal: `decimal(15,2)` for money/qty.
- Enum: MySQL enum + CHECK constraint (MySQL 8 supports CHECK).
- Index: composite always starts with `company_id` (tenant scope).

## Tables by Module

### Core (app/Models/Core/)

- `companies` — id, name, code, address, phone, email, tax_id, logo,
  timezone, settings (json), is_active. Unique: code (global).
- `users` — id, name, email, email_verified_at, password, company_id
  (nullable FK), is_active, remember_token, 2FA fields. Unique: email.
  Soft delete.
- `roles` / `permissions` / pivots — spatie standard. Global.
- `activity_log` — spatie standard. Global. Polymorphic.
- `locations` — company_id, parent_id (self-ref), code, name, type
  (region/area/pop/rack/site), path, is_active.
- `customers` — company_id, code, name, type (individual/company),
  tax_id, phone, email, address, is_active. Soft delete.
- `customer_addresses` — customer_id, label, address, city, regency,
  province, postal_code, is_default. Soft delete.
- `customer_contacts` — customer_id, name, phone, email, role,
  is_primary. Soft delete.
- `service_subscriptions` — company_id, customer_id, service_package_id,
  code, status (active/suspended/terminated), activation_date,
  suspension_date, billing_day, ont_asset_id (FK network_assets,
  exception D-R5). Soft delete.
- `employee_evaluations` — company_id, employee_id, reference_type,
  reference_id, rating, notes, evaluated_at.

### Service (Modules/Service/Models/)

- `service_packages` — company_id, name, type, mrc, otc, description,
  is_active. Soft delete.
- `bandwidth_profiles` — company_id, name, down_mbps, up_mbps,
  is_active. Soft delete.
- `speed_profiles` — company_id, name, down_mbps, up_mbps,
  is_active. Soft delete.
- `sla_tiers` — company_id, name, uptime_pct, response_time_hours,
  resolution_time_hours, credit_pct, is_active. Soft delete.

### NetworkAsset (Modules/NetworkAsset/Models/)

- `network_assets` — company_id, serial_number, mac_address,
  ip_address, type, model, brand, status (in_stock/installed/
  maintenance/retired), location_id, customer_id, subscription_id.
  Unique: (company_id, serial_number). Soft delete.
- `network_asset_installations` — network_asset_id, location_id,
  customer_id, subscription_id, spk_id, installed_at, removed_at.
  Append-only (no soft delete).

### Inventory (Modules/Inventory/Models/)

- `products` — company_id, sku, name, category_id, unit_id,
  description, is_active. Unique: (company_id, sku). Soft delete.
- `categories` — company_id, name, parent_id (self-ref), is_active.
  Soft delete.
- `units` — company_id, name, symbol, is_active. Soft delete.
- `stocks` — company_id, product_id, location_id, quantity.
  Unique: (company_id, product_id, location_id).
- `stock_movements` — company_id, product_id, from_location_id,
  to_location_id, quantity, reference_type, reference_id, type
  (receive/issue/transfer/adjust/return), created_at. Append-only
  (immutable, no soft delete).

### SPK (Modules/SPK/Models/)

- `work_orders` — company_id, code, type (install/maintain/upgrade/
  relocate), status (pending/assigned/in_progress/completed/cancelled),
  customer_id, subscription_id, location_id, assigned_to, scheduled_at,
  started_at, completed_at, priority, notes. Unique: (company_id, code).
  Soft delete.
- `work_order_items` — work_order_id, product_id, quantity, type
  (issue/return). Cascade delete.
- `work_order_assignments` — work_order_id, user_id, role, assigned_at.
  Append-only.
- `work_order_evidence` — work_order_id, file_path, type, notes.

### Billing (Modules/Billing/Models/)

- `invoices` — company_id, number, customer_id, subscription_id,
  type (mrc/otc/adjustment), status (draft/sent/paid/overdue/
  cancelled), issue_date, due_date, subtotal, tax_amount, total,
  paid_amount, notes. Unique: (company_id, number). Soft delete.
- `invoice_items` — invoice_id, description, quantity, unit_price,
  total. Cascade delete.
- `payments` — company_id, invoice_id, amount, method, paid_at,
  reference_no. Append-only (no soft delete).

### Ticketing (Modules/Ticketing/Models/)

- `tickets` — company_id, code, customer_id, category_id,
  network_asset_id, subscription_id, title, description, status
  (open/assigned/in_progress/resolved/closed), priority, sla_deadline,
  assigned_to, resolved_at, closed_at. Unique: (company_id, code).
  Soft delete.
- `ticket_categories` — company_id, name, sla_hours, priority_default,
  is_active. Soft delete.
- `ticket_comments` — ticket_id, user_id, body, is_internal. Cascade
  delete.
- `ticket_attachments` — ticket_id, file_path, filename, mime.

### Reporting (Modules/Reporting/)

- NO models. NO migrations. NO writes.
- Query classes only: `TechnicianPerformanceQuery`,
  `UnitPerformanceQuery`, `BusinessMetricsQuery`,
  `AssetUtilizationQuery`, `SlaComplianceQuery`.
- Exception to "no direct cross-module Model import" rule.

## ERD (textual — core relations)

```
companies 1──N users
companies 1──N locations (self-ref parent)
companies 1──N customers
customers 1──N customer_addresses
customers 1──N customer_contacts
customers 1──N service_subscriptions
service_packages 1──N service_subscriptions
network_assets N──1 locations
network_assets N──1 customers (installed at)
network_assets N──1 service_subscriptions (linked)
customers 1──N work_orders
service_subscriptions 1──N work_orders
work_orders 1──N work_order_items
work_orders 1──N work_order_assignments
work_orders 1──N work_order_evidence
customers 1──N invoices
service_subscriptions 1──N invoices
invoices 1──N invoice_items
invoices 1──N payments
customers 1──N tickets
ticket_categories 1──N tickets
tickets 1──N ticket_comments
tickets 1──N ticket_attachments
```

## Index Strategy

- Composite index always starts with `company_id`.
- FK auto-indexed.
- Unique per-company: `(company_id, code)`, `(company_id, serial_number)`,
  `(company_id, sku)`, `(company_id, number)`.
- MySQL index name limit: 64 chars. Use custom short names for long
  composite indexes.

## Migration & Seed Strategy

- Migration order: Core → Service → NetworkAsset → Inventory → SPK →
  Billing → Ticketing → Reporting.
- Seed: roles + permissions + system settings ONLY. NO company data.
- Company created via Setup Wizard (`php artisan inbils:setup`).
- Per-company master data seeded by `CompanySeeder::runFor($company)`
  triggered by `CompanyCreated` event (units, ticket_categories,
  sla_tiers, sample locations).
