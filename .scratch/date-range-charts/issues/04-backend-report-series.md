# BACKEND: time buckets and series for five reports

Status: done
Role: backend
Blocked by: none
Spec: `../spec.md`

## Scope (owned files)

- New `Modules/Reporting/app/Support/TimeBuckets.php` per the series contract (granularity rule Q6, Jakarta-safe via `CONVERT_TZ` with named zones — check the MySQL image has tz tables: `SELECT CONVERT_TZ('2026-01-01','UTC','Asia/Jakarta')`; if NULL, use the numeric offset computed in PHP for the range and document the DST limitation with a `shortcut:` comment).
- Queries: `BusinessMetricsQuery` (series `revenue_paid`, `mrr`; `invoice_status`), `SalesKpiQuery` (series `new_customers`), `SlaComplianceQuery` (`compliance`), `TechnicianPerformanceQuery` (series `spk_completed`), `AssetUtilizationQuery` (unchanged shape, verify `by_status`/`by_location` arrays). Keep existing keys untouched so CSV/PDF exporters and drill links keep working.
- Resources/controllers only where the new keys must be passed through.
- Tests: `tests/Unit/Reporting/TimeBucketsTest.php` (granularity thresholds, empty-fill, labels, 36-bucket cap) and one feature test per query asserting series points across buckets with fixtures.
- Do not touch `ReportFilterRequest` or `AppliesDateRange` (ticket 03).

## Acceptance criteria

- [x] Focused PHPUnit green; Pint; `EXPLAIN` of one bucketed query pasted (index on the date column used).

## Comments

### Decisions (BeSeries, 2026-10-13)

- **MySQL time zone tables: present.** Probe on the dev server (8.4.11; `inbils_test` lives on the same server): `SELECT CONVERT_TZ('2026-01-01 00:00:00','UTC','Asia/Jakarta')` → `2026-01-01 07:00:00` (not NULL), session/system zone UTC. So named zones are used, no PHP offset shortcut. `ReportSeriesTest::test_mysql_converts_named_time_zones` fails loudly if a server ever lacks the tables (`mysql_tzinfo_to_sql`). `config('app.timezone')` is the literal `'UTC'` (not env-driven); timestamps are stored in it.
- **Class and namespace.** `Modules\Reporting\Support\TimeBuckets` in `Modules/Reporting/app/Support/TimeBuckets.php` (the module PSR-4 root is `Modules/Reporting/app`). The spec text `App\Support\Reporting\TimeBuckets` is a slip. Contract kept: `forRange(?CarbonImmutable, ?CarbonImmutable, string $tz)` plus an optional 4th argument `?CarbonInterface $earliest` (the oldest row, needed for "no From = month buckets from the earliest row"), `granularity()`, `keys()`, `keyFor()`, `label()`, `sqlExpression()` (timestamps, `CONVERT_TZ` from the storage zone; skipped when the bucket zone is the storage zone). Added: `sqlDateExpression()` for DATE columns (`issue_date`, no zone applies), `forPeriod()` (From/To strings), `endDate()`, `series()` (zero-fill, fold, cut), `timezoneOf()` (company zone, else app zone).
- **Granularity.** Days counted with both ends included: ≤ 31 → day, ≤ 184 → week (ISO, Monday; 184 = Jul–Dec), else month. Open start (all time, or To alone) → month from the oldest row; open end → today in the bucket zone; inverted → collapses on From. Month buckets are capped at 36 for every month series (not only all time); older months fold into the first bucket; rows after the last bucket (future-dated) are not drawn. Labels: `13 Okt` / `W42 2026` (two-digit week) / `Okt 2026`, Carbon `id` locale (same abbreviations as date-fns `id`, `Agt`).
- **Contract change: `charts` prop, not `data.*`.** `MetricRegistryTest` and `ReportMetaPropsTest` pin every key of `execute()` and of the page `data` to a registered metric (`meta.metrics`), and `ConsolidatedBusinessQuery::total()` throws on any Business key without a consolidation rule. Series are not metrics, so they travel in a new top-level Inertia prop `charts` next to `data`/`meta`/`drill` (`ReportFilters::charts()`, `execute()` untouched, exports/consolidation/drill unchanged). FeCharts was told the final keys.
- **MRR per bucket** = MRR at the end of the bucket: monthly fee (today's) of every subscription with `activation_date` ≤ bucket end and not terminated by then (`terminated_at` NULL or after the end of that day in the bucket zone); the last bucket stops at the period end. Suspensions leave no history, so a subscription suspended today still counts in the past and the last point can exceed the MRR card (active only). One scan of `service_subscriptions`, one `SUM(CASE …)` per bucket.
- **Time zone of the buckets.** Buckets and the series' own period filter use the company timezone (`companies.timezone`, spec Q6). The existing figures (`execute()`, drill lists, exports) read From/To as calendar days of the application timezone (UTC): `AppliesDateRange::dateRange()` has no company-zone default (only the consolidated report passes one). So a Technician/Sales series bucket can differ from its card for rows within the 7 hours around midnight at the edges of the period (e.g. Jakarta 00:00–06:59). Business revenue is a DATE column and SLA/Asset charts are splits of card figures: they always tie out. Recommended follow-up (owner decision, ticket 03 territory): make `AppliesDateRange` read dates in the company zone, then cards, drill lists and series agree; the series code needs no change.

### Final keys (sent to FeCharts)

```
Business   charts = { series: {granularity, points:[{bucket,label,revenue_paid,mrr}]}, invoice_status:[{status,count}] }
Sales      charts = { series: {granularity, points:[{bucket,label,new_customers}]}, acquirers:[{name,customers_acquired,collected}] }   // every acquirer, unpaginated, current sort, unassigned excluded
Sla        charts = { compliance: {compliant, breached} }                 // the sla_compliant / breach_count figures; 0/0 when none
Technician charts = { series: {granularity, points:[{bucket,label,spk_completed}]} } | null (no technician selected)
Asset      no charts prop: data.status_distribution (status→count), data.by_location (name→count); an empty map serialises as []
```

Values are JSON numbers (counts int, money float). `invoice_status`: `draft|sent|partial|paid|overdue|cancelled`, count > 0 only, that order, invoices issued in the period (all without a range), soft-deleted excluded.

### Evidence

- `php artisan test --compact tests/Unit/Reporting/TimeBucketsTest.php` → 26 passed (62 assertions).
- `tests/Feature/Reporting/ReportSeriesTest.php` → 22 passed (138 assertions): per-day/week/month/all-time/from-only/to-only Business series with hand-worked MRR across a Jakarta termination boundary, invoice status order and scope, Technician day buckets on the 00:00/23:59:59 Jakarta edges and a UTC company, 36-month fold, Sales series + acquirer bars (12 acquirers, 10 per page), SLA compliance, Asset maps, the `charts` prop on the five pages.
- Focused regression: `tests/Feature/Reporting tests/Feature/ReportFilterValidationTest.php tests/Unit/Reporting tests/Unit/PhpUnitTopologyTest.php` → **325 passed (7339 assertions)**, MySQL `inbils_test`, in the sail container.
- `vendor/bin/pint --test --format agent` on all touched files → `{"tool":"pint","result":"passed"}`. `phpstan analyse` (level 5) on the touched sources: no finding in new code (existing ones remain in `SalesKpiQuery::accumulate`, `SlaComplianceQuery::execute`).
- Series vs card on the dev DB (company 1, mid-day rows): Business revenue Σ 51598492.04 = card; Sales new customers Σ 84 = 84 (53 = 53 for 2026-09-01..10-13); Technician 4 = 4.
- `EXPLAIN` of the Business revenue series, `2026-10-01..2026-10-13` (dev DB, 152 invoices):

```
select DATE_FORMAT(`issue_date`, '%Y-%m-%d') as bucket, SUM(paid_amount) as amount from `invoices`
where `invoices`.`company_id` = 1 and `status` = 'paid' and `issue_date` >= '2026-10-01 00:00:00'
  and `issue_date` <= '2026-10-13 23:59:59' and `invoices`.`deleted_at` is null group by `bucket`
→ type=range  key=invoices_company_id_issue_date_index  key_len=11  rows=28  filtered=1.67
  Extra: Using index condition; Using where; Using temporary
```

  Over a wide range on this tiny table the planner prefers `invoices_company_id_status_index` (115 of 152 rows are paid); `possible_keys` still lists the date index and `FORCE INDEX` gives `type=range`. `Using temporary` is the GROUP BY of the computed bucket (≤ 36 groups).
- Technician series plan: `ref` on `work_orders_assigned_to_foreign`. Sales series plan: full scan of `customers` (86 rows in dev).

### Risks / not verified

- No index on `work_orders.completed_at`, `tickets.resolved_at`, `customers.created_at` (no migration in this ticket); the series reuse the company/technician indexes and a range filter, as the cards do. Add `(company_id, created_at)` on customers if the Sales report gets slow.
- Not exercised in a browser or E2E (orchestrator). Not run: other suites outside Reporting.
- Needs doc/changelog by the orchestrator: `CHANGELOG.md` entry; spec contract text `data.series|invoice_status|compliance` → `charts.*` (spec Contracts section); optional `docs/business/reporting.md`.
