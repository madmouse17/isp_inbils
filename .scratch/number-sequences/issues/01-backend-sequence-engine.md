# BACKEND: sequence engine (templates, reset, registry, editor endpoints)

Status: done
Role: backend
Blocked by: none
Spec: `../spec.md`

## Scope

Owned files:
- `app/Services/Core/NumberSequenceService.php`, `app/Models/Core/NumberSequence.php`, `database/factories/NumberSequenceFactory.php`
- New `app/Support/NumberSequences/{NumberSequenceDefinition,NumberSequenceRegistry,NumberTemplate,ResetPeriod}.php`
- New migration `database/migrations/2026_10_13_100000_add_template_and_reset_to_number_sequences_table.php`
- `app/Http/Controllers/Admin/NumberSequenceController.php`, new `app/Http/Requests/Admin/UpdateNumberSequenceRequest.php`, `app/Http/Resources/NumberSequenceResource.php`, `app/Policies/NumberSequencePolicy.php` (if touched)
- Registration lines only: `app/Providers/AppServiceProvider.php` (customer, employee, subscription; registry singleton) and the providers of the Billing (invoice), SPK (spk), Ticketing (ticket), Inventory (inventory_request, product) and NetworkAsset (network_asset) modules
- `tests/Feature/NumberSequenceTest.php`, new engine/editor tests
- The `CHANGELOG.md` block

## Contract (fixed; tickets 02 and 03 build against it)

```php
namespace App\Support\NumberSequences;
enum ResetPeriod: string { case Never = 'never'; case Yearly = 'yearly'; case Monthly = 'monthly'; case Daily = 'daily'; }
final readonly class NumberSequenceDefinition {
    public function __construct(
        public string $entityType, public string $label, public string $table, public string $column,
        public int $maxLength, public string $defaultPrefix, public string $defaultTemplate,
        public ResetPeriod $defaultReset = ResetPeriod::Never, public bool $defaultAllowManual = false,
    ) {}
}
final class NumberSequenceRegistry { // singleton
    public function register(NumberSequenceDefinition $definition): void;
    public function get(string $entityType): NumberSequenceDefinition; // InvalidArgumentException when unknown
    /** @return array<string, NumberSequenceDefinition> */ public function all(): array;
}

namespace App\Services\Core;
class NumberSequenceService {
    public static function generate(string $entityType, ?int $companyId = null): string; // the old ?string $prefix parameter is removed
    public static function peek(string $entityType, ?int $companyId = null): string;       // never null
    public static function allowsManual(string $entityType, ?int $companyId = null): bool;
    /** A non-blank $requested code when manual input is allowed, else generate(). A non-blank code while manual is off → ValidationException on $field. */
    public static function resolve(string $entityType, ?string $requested, string $field = 'code', ?int $companyId = null): string;
    public static function ensureAll(int $companyId): void; // creates any missing registered rows
}
```

Schema changes to `number_sequences`:
- Add `template` string(100), `reset_period` string(10) default `never`, `period_key` string(10) nullable, `allow_manual` boolean default false.
- Convert `padding`/`year_suffix` into `template`, then drop both columns. `down()` restores them from the template.

Engine behaviour:
- Tokens, the literal set, the reset rules and the defaults are as in spec Q3, Q4 and R2.
- The rendered length must be ≤ the definition's `maxLength`.
- `{COMPANY}` is `companies.code`.
- Generation runs under `lockForUpdate`:
  - When the period key changed, `next_number` resets to 1.
  - It renders a code and skips any that already exist, checked with `DB::table($table)->where('company_id', …)->where($column, $code)`. It gives up with a RuntimeException after 1000 skips.
  - It joins the caller's transaction where one exists.
- When a row is created (first use or `ensureAll`), `next_number` = 1 + the highest `{SEQ}` parsed from existing codes in the column that match the template in the current period.
- When an update changes the template or the reset period, `period_key` is set to the current key.

Page `Admin/NumberSequences/Index` props:

```ts
sequences: Array<{ id: number; entity_type: string; label: string; prefix: string; template: string; next_number: number;
  reset_period: 'never' | 'yearly' | 'monthly' | 'daily'; allow_manual: boolean; preview: string; max_length: number; updated_at: string | null }>
company_code: string
tokens: Array<{ token: string; description: string }>
```

- `index` calls `ensureAll(current company)` and lists every registered entity, sorted by label, without pagination. The export route stays.
- `update` route `admin.number-sequences.update` (existing) uses `UpdateNumberSequenceRequest`:
  - Body: `prefix` (`[A-Za-z0-9_-]{1,20}`), `template`, `next_number` (int ≥ 1 and < 10^n), `reset_period`, `allow_manual`.
  - Errors are keyed by field and written in Indonesian.
  - Redirects back with flash `Penomoran diperbarui.`
  - Writes the change to the activity log.
  - Authorised by `system.setting`.

## Acceptance criteria

- [x] The engine tests listed in the spec's testing decisions pass. (Engine level: template validation, every token, reset per period with the company-timezone boundary, skip-taken, ensure-from-max, per-company isolation, row-lock query, `resolve`/`allowsManual` for customer, employee and product. The HTTP create/update enforcement of `allow_manual` belongs to ticket 02.)
- [x] The migration runs up and down on `inbils_test`.
- [x] `peek` and `generate` agree.
- [x] Existing invoice/SPK/subscription/network_asset codes keep their format.

## Comments

### 2026-10-13 SeqCore (backend) - done

Delivered: `app/Support/NumberSequences/{ResetPeriod,NumberSequenceDefinition,NumberSequenceRegistry,NumberTemplate}.php`; engine `NumberSequenceService` (`generate`, `peek`, `previewFor`, `allowsManual`, `resolve`, `ensureAll`, `update`); model, factory, migration `2026_10_13_100000_add_template_and_reset_to_number_sequences_table.php`; `NumberSequenceController` (index with `ensureAll`, update, export), `UpdateNumberSequenceRequest`, `NumberSequenceResource`; registry singleton and customer/employee/subscription in `AppServiceProvider`; invoice, spk, ticket, inventory_request, product and network_asset in the module providers; CHANGELOG block.

Decisions and deviations:
- R4 was tightened by the orchestrator during the work: date tokens and period keys use the company timezone (`$company->timezone ?: config('app.timezone')`, one `now($tz)` per generate/peek). `spec.md` R4 still says "when a helper already exists"; update it.
- Reset-period matching in ensure-from-max: date parts that identify the period are fixed to the current period, finer parts match anything (yearly + `{MM}`: any month; never + `{YYYY}`: any year). Free-form or non-matching codes are ignored; a template without `{SEQ}` starts at 1 and never throws.
- A stored `period_key` of null on a periodic row joins the running period (the counter is kept); only a different stored key restarts at 1.
- `previewFor()` (public) lets the Penomoran page show a preview per row without a company lookup per row; it falls back to the stored counter instead of throwing when 1000 taken codes block the counter, so the page can still move it.
- `next_number` is capped at 4294967295 (the `unsigned int` column), `{SEQ:10}` included; `update()` only takes the five editable fields.
- `NumberSequenceServerTableExportTest` was updated (old index assertions used `sequences.data`, `padding`, `year_suffix`); the export keeps its route and filters and lists Template, Reset Period and Allow Manual instead of Padding and Year Suffix.
- Known limit (pre-existing pattern): two requests that create the very first row of an entity at the same instant can deadlock on the gap lock; one fails and retrying works. `ensureAll` (Penomoran page) removes the case.

Evidence (inbils_test, via sail as the sail user, serialised with the shared flock):
- `php -d memory_limit=1G artisan test --compact tests/Unit/NumberTemplateTest.php tests/Feature/NumberSequenceTest.php tests/Feature/NumberSequenceMigrationTest.php tests/Feature/Admin/NumberSequenceEditorTest.php tests/Feature/Admin/NumberSequenceServerTableExportTest.php` -> `Tests: 88 passed (542 assertions)`.
- `vendor/bin/pint --test --format agent <owned files>` -> `{"tool":"pint","result":"passed"}`.
- `vendor/bin/phpstan analyse <owned files>` (level 5, Larastan) -> one finding, `NumberSequenceController::SORTABLE is unused`, which the same trait pattern raises on `VehicleController` too (pre-existing).
- `php artisan route:list --path=number-sequences` -> index, export and `PUT|PATCH admin/number-sequences/{number_sequence}`.
- `php artisan migrate --pretend --path=database/migrations/2026_10_13_100000_...` -> add `template` (nullable), `reset_period` (default `never`), `period_key`, `allow_manual`; fill `template` from `padding`/`year_suffix`; `modify template varchar(100) not null`; `drop padding, drop year_suffix`.
