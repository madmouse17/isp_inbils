<?php

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\SettingService;
use App\Support\PublicId;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

// Canonical E2E contract (AGENTS.md Test Database Rule): every automated
// test/QA/browser/E2E run MUST use the single shared MySQL schema `inbils_test`.
// No disposable/random schema is ever created and no schema is ever dropped;
// the effective database is resolved from .env.e2e (DB_DATABASE=inbils_test)
// and enforced below — anything else is refused (fail closed). The previous
// disposable namespace is gone: neither E2E_DATABASE nor any other env var
// can re-target the run to a different schema.
const canonicalDatabase = 'inbils_test';

if (nonEmptyEnvValue('E2E_DATABASE') !== null) {
    fwrite(STDERR, 'Refusing E2E reset: E2E_DATABASE is no longer supported; all E2E runs use '.canonicalDatabase.".\n");
    exit(1);
}

define('E2E_DATABASE', canonicalDatabase);

$checkOnly = in_array('--check', $argv, true);
$root = dirname(__DIR__, 2);

rejectCachedConfig($root);
rejectProcessAppEnv();
rejectProcessDatabaseUrl();

$environmentFile = $root.'/.env.e2e';

if (! is_file($environmentFile)) {
    fwrite(STDERR, "E2E setup requires .env.e2e copied from .env.e2e.example.\n");
    exit(1);
}

$_SERVER['argv'][] = '--env=e2e';

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

guardE2eApp($app);
[$connectionName, $database] = guardE2eDatabase();

if ($checkOnly) {
    fwrite(STDOUT, "E2E database guard passed for {$connectionName}/{$database}.\n");
    exit(0);
}

$kernel = $app->make(Kernel::class);

try {
    $exitCode = $kernel->call('migrate:fresh', [
        '--seed' => true,
        '--seeder' => 'Database\Seeders\DatabaseSeeder',
        '--force' => true,
    ]);

    if ($exitCode === 0) {
        $exitCode = $kernel->call('db:seed', [
            '--class' => 'Database\Seeders\DemoUserSeeder',
            '--force' => true,
        ]);
    }

    if ($exitCode === 0) {
        $exitCode = $kernel->call('db:seed', [
            '--class' => 'Database\Seeders\E2eFixtureSeeder',
            '--force' => true,
        ]);
    }

    if ($exitCode === 0) {
        $exitCode = $kernel->call('db:seed', [
            '--class' => 'Database\Seeders\E2ePlatformSeeder',
            '--force' => true,
        ]);
    }

    if ($exitCode === 0) {
        seedFlowAuditWaveOneFixtures();
    }

    // Midtrans, WhatsApp and RADIUS are configured per company now: give every seeded company the values of the environment
    // (.env.e2e, and the RADIUS_* the lab runner exports). A provider without values is skipped.
    if ($exitCode === 0) {
        foreach (Company::query()->orderBy('id')->pluck('code') as $code) {
            $exitCode = $kernel->call('inbils:integrations:import-env', [
                '--company' => $code,
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                break;
            }
        }
    }

    exit($exitCode);
} catch (Throwable $e) {
    fwrite(STDERR, "E2E setup failed with unhandled exception: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    exit(1);
}

/** Browser-only records for `.scratch/flow-audit-fixes/design-w1.md` S13. */
function seedFlowAuditWaveOneFixtures(): void
{
    $company = Company::query()->where('code', 'DEMO')->firstOrFail();

    CompanyService::runAs((int) $company->id, function () use ($company): void {
        $now = Carbon::parse('2026-10-20 03:00:00', 'UTC');
        $timestamps = ['created_at' => $now, 'updated_at' => $now];
        $admin = User::query()->where('email', 'admin@demo.inbils.test')->firstOrFail();
        $technician = User::query()->where('email', 'technician@demo.inbils.test')->firstOrFail();
        $customerId = DB::table('customers')->where('company_id', $company->id)->where('code', 'E2E-LINKED-CUST')->value('id');
        $subscriptionId = DB::table('service_subscriptions')->where('company_id', $company->id)->where('code', 'E2E-LINKED-SUB')->value('id');
        $category = DB::table('ticket_categories')->where('company_id', $company->id)->where('is_active', true)->orderBy('id')->first();

        if (! $customerId || ! $subscriptionId || ! $category) {
            throw new LogicException('Flow-audit E2E fixtures require the linked customer, subscription, and a ticket category.');
        }

        $company->forceFill(['phone' => '0812-3456-7890'])->save();
        SettingService::set('play_store_url_customer', 'https://play.google.com/store/apps/details?id=id.inbils.customer');

        foreach ([
            [
                'code' => 'E2E-W1-TICKET-CLOSED',
                'title' => 'E2E W1 Tiket ditutup',
                'status' => 'closed',
                'first_response_at' => '2026-10-20 01:00:00',
                'first_response_source' => 'reply',
                'resolved_at' => null,
                'reopened_at' => null,
                'closed_at' => '2026-10-20 03:00:00',
                'resolution_note' => 'Gangguan sudah ditangani.',
                'category_name_snapshot' => $category->name,
                'assignee_name_snapshot' => $technician->name,
            ],
            [
                'code' => 'E2E-W1-TICKET-REOPENED',
                'title' => 'E2E W1 Tiket dibuka kembali',
                'status' => 'assigned',
                'first_response_at' => '2026-10-20 01:00:00',
                'first_response_source' => 'reply',
                'resolved_at' => null,
                'reopened_at' => '2026-10-20 03:00:00',
                'closed_at' => null,
                'resolution_note' => null,
                'category_name_snapshot' => null,
                'assignee_name_snapshot' => null,
            ],
            [
                'code' => 'E2E-W1-TICKET-STARTED',
                'title' => 'E2E W1 Respons lewat Mulai',
                'status' => 'on_progress',
                'first_response_at' => '2026-10-20 02:00:00',
                'first_response_source' => 'start',
                'resolved_at' => null,
                'reopened_at' => null,
                'closed_at' => null,
                'resolution_note' => null,
                'category_name_snapshot' => null,
                'assignee_name_snapshot' => null,
            ],
        ] as $fixture) {
            Ticket::withTrashed()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $fixture['code']],
                [
                    ...$fixture,
                    'description' => 'Fixture browser flow audit wave 1.',
                    'source' => 'internal',
                    'category_id' => $category->id,
                    'priority' => 'medium',
                    'customer_id' => $customerId,
                    'subscription_id' => $subscriptionId,
                    'network_asset_id' => null,
                    'location_id' => null,
                    'assigned_to' => $technician->id,
                    'spawned_spk_id' => null,
                    'sla_deadline' => '2026-10-22 01:00:00',
                    'response_deadline' => '2026-10-21 01:00:00',
                    'created_by' => $admin->id,
                    'deleted_at' => null,
                ],
            );
        }

        $creator = User::query()->updateOrCreate(
            ['email' => 'billing-creator@qa.inbils.test'],
            [
                'name' => 'E2E Billing Creator',
                'email_verified_at' => $now,
                'password' => Hash::make(DemoUserSeeder::PASSWORD),
                'is_active' => true,
            ],
        );
        $creator->forceFill(['company_id' => $company->id])->save();
        $creator->syncRoles([]);
        $creator->syncPermissions(['app.web', 'dashboard.view', 'billing.view', 'billing.create']);
        DB::table('company_user')->updateOrInsert(
            ['company_id' => $company->id, 'user_id' => $creator->id],
            ['is_default' => true, 'created_at' => $now, 'updated_at' => $now],
        );

        $invoice = function (string $number, array $values) use ($admin, $company, $customerId, $timestamps): int {
            DB::table('invoices')->updateOrInsert(
                ['company_id' => $company->id, 'number' => $number],
                PublicId::onInsert([
                    'type' => 'one_time',
                    'source' => 'manual',
                    'customer_id' => $customerId,
                    'subscription_id' => null,
                    'work_order_id' => null,
                    'issue_date' => '2026-10-20',
                    'due_date' => '2026-11-03',
                    'billing_period_start' => null,
                    'billing_period_end' => null,
                    'status' => 'draft',
                    'subtotal' => 0,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                    'total' => 0,
                    'paid_amount' => 0,
                    'notes' => null,
                    'created_by' => $admin->id,
                    'sent_at' => null,
                    'cancelled_at' => null,
                    'cancel_reason' => null,
                    'cancelled_by' => null,
                    'deleted_at' => null,
                    ...$values,
                    ...$timestamps,
                ]),
            );

            return (int) DB::table('invoices')->where('company_id', $company->id)->where('number', $number)->value('id');
        };

        $installationWorkOrder = WorkOrder::withTrashed()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'E2E-W1-INSTALLATION-INVOICE'],
            [
                'type' => 'installation',
                'title' => 'E2E W1 SPK tagihan pemasangan',
                'description' => 'Fixture tagihan pemasangan draf.',
                'status' => 'completed',
                'customer_id' => $customerId,
                'subscription_id' => $subscriptionId,
                'location_id' => DB::table('service_subscriptions')->where('id', $subscriptionId)->value('serving_pop_id'),
                'assigned_to' => $technician->id,
                'ticket_id' => null,
                'source' => 'manual',
                'priority' => 'medium',
                'scheduled_date' => '2026-10-20',
                'started_at' => '2026-10-20 01:00:00',
                'submitted_at' => '2026-10-20 02:00:00',
                'completed_at' => '2026-10-20 03:00:00',
                'result' => 'Pemasangan selesai.',
                'rejection_reason' => null,
                'created_by' => $admin->id,
                'approved_by' => $admin->id,
                'approved_at' => '2026-10-20 03:00:00',
                'rejected_by' => null,
                'rejected_at' => null,
                'deleted_at' => null,
            ],
        );

        $wholeInvoiceId = $invoice('E2E-W1-WHOLE', [
            'source' => 'spk',
            'work_order_id' => $installationWorkOrder->id,
            'subtotal' => 147702,
            'total' => 147702,
            'notes' => 'Flow audit whole rupiah',
            'created_by' => $creator->id,
        ]);
        DB::table('invoice_items')->updateOrInsert(
            ['company_id' => $company->id, 'invoice_id' => $wholeInvoiceId, 'description' => 'E2E whole rupiah line'],
            PublicId::onInsert([
                'product_id' => null,
                'quantity' => 1,
                'unit_price' => 147702,
                'discount_amount' => 0,
                'tax_rate' => 0,
                'line_total' => 147702,
                ...$timestamps,
            ]),
        );

        $emptyInvoiceId = $invoice('E2E-W1-EMPTY', ['notes' => 'Flow audit empty draft']);
        DB::table('invoice_items')->where('invoice_id', $emptyInvoiceId)->delete();

        $refundInvoiceId = $invoice('E2E-W1-REFUND', [
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-15',
            'status' => 'cancelled',
            'subtotal' => 100000,
            'total' => 100000,
            'sent_at' => $now,
            'cancelled_at' => $now,
            'cancel_reason' => 'Fixture pembayaran online perlu dikembalikan.',
            'cancelled_by' => $admin->id,
            'customer_name_snapshot' => 'E2E Linked Customer',
        ]);
        DB::table('invoice_items')->updateOrInsert(
            ['company_id' => $company->id, 'invoice_id' => $refundInvoiceId, 'description' => 'E2E refund invoice line'],
            PublicId::onInsert([
                'product_id' => null,
                'quantity' => 1,
                'unit_price' => 100000,
                'discount_amount' => 0,
                'tax_rate' => 0,
                'line_total' => 100000,
                ...$timestamps,
            ]),
        );
        DB::table('payment_attempts')->updateOrInsert(
            ['order_id' => 'E2E-W1-REFUND-ORDER'],
            PublicId::onInsert([
                'company_id' => $company->id,
                'invoice_id' => $refundInvoiceId,
                'amount' => 100000,
                'status' => 'exception',
                'status_reason' => 'gross_amount tidak sesuai',
                'snap_token' => null,
                'redirect_url' => null,
                'transaction_id' => 'E2E-W1-REFUND-TRX',
                'payment_type' => 'bank_transfer',
                'last_notification' => null,
                'expires_at' => $now->copy()->addDay(),
                'settled_at' => null,
                'payment_id' => null,
                'resolved_by' => null,
                'resolved_at' => null,
                'resolution_reference' => null,
                'resolution_note' => null,
                ...$timestamps,
            ]),
        );

        $unitId = DB::table('units')->where('company_id', $company->id)->where('symbol', 'm')->value('id');
        if (! $unitId) {
            $unitId = DB::table('units')->insertGetId([
                'public_id' => PublicId::new(),
                'company_id' => $company->id,
                'name' => 'Meter',
                'symbol' => 'm',
                'is_active' => true,
                'deleted_at' => null,
                ...$timestamps,
            ]);
        }
        $categoryId = DB::table('categories')->where('company_id', $company->id)->orderBy('id')->value('id');
        DB::table('locations')->updateOrInsert(
            ['company_id' => $company->id, 'code' => 'E2E-W1-POP'],
            PublicId::onInsert([
                'parent_id' => null,
                'name' => 'AUDIT POP Bandung Timur',
                'type' => 'pop',
                'path' => null,
                'address' => null,
                'lat' => null,
                'lng' => null,
                'is_active' => true,
                'deleted_at' => null,
                ...$timestamps,
            ]),
        );
        $locationId = (int) DB::table('locations')->where('company_id', $company->id)->where('code', 'E2E-W1-POP')->value('id');
        DB::table('products')->updateOrInsert(
            ['company_id' => $company->id, 'sku' => 'E2E-W1-SHORT-STOCK'],
            PublicId::onInsert([
                'category_id' => $categoryId,
                'unit_id' => $unitId,
                'name' => 'Kabel Fiber Optic Single',
                'description' => null,
                'type' => 'consumable',
                'track_stock' => true,
                'sell_price' => 1,
                'cost_price' => 1,
                'min_stock' => 0,
                'is_active' => true,
                'deleted_at' => null,
                ...$timestamps,
            ]),
        );
        $productId = (int) DB::table('products')->where('company_id', $company->id)->where('sku', 'E2E-W1-SHORT-STOCK')->value('id');
        DB::table('stocks')->updateOrInsert(
            ['company_id' => $company->id, 'product_id' => $productId, 'location_id' => $locationId],
            PublicId::onInsert(['quantity' => 0, 'reserved_quantity' => 0, ...$timestamps]),
        );
        $workOrder = WorkOrder::withTrashed()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'E2E-W1-SHORT-STOCK'],
            [
                'type' => 'maintenance',
                'title' => 'E2E W1 SPK stok kurang',
                'description' => 'Fixture browser flow audit wave 1.',
                'status' => 'waiting_review',
                'customer_id' => $customerId,
                'subscription_id' => null,
                'location_id' => $locationId,
                'assigned_to' => $technician->id,
                'ticket_id' => null,
                'source' => 'manual',
                'priority' => 'medium',
                'scheduled_date' => '2026-10-20',
                'started_at' => '2026-10-20 01:00:00',
                'submitted_at' => '2026-10-20 02:00:00',
                'completed_at' => null,
                'result' => null,
                'rejection_reason' => null,
                'created_by' => $admin->id,
                'approved_by' => null,
                'approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'deleted_at' => null,
            ],
        );
        DB::table('work_order_items')->updateOrInsert(
            ['company_id' => $company->id, 'work_order_id' => $workOrder->id, 'product_id' => $productId],
            PublicId::onInsert([
                'network_asset_id' => null,
                'quantity_reserved' => 0,
                'quantity_used' => 18,
                'note' => null,
                ...$timestamps,
            ]),
        );
        if ($workOrder->getMedia('evidence')->isEmpty()) {
            $workOrder->addMediaFromString('flow-audit-evidence')
                ->usingFileName('flow-audit-evidence.jpg')
                ->withCustomProperties(['company_id' => $company->id, 'type' => 'photo', 'uploaded_by' => $technician->id])
                ->toMediaCollection('evidence', 'public');
        }
    });
}

function rejectCachedConfig(string $root): void
{
    foreach (['APP_CONFIG_CACHE', 'LARAVEL_CONFIG_CACHE'] as $key) {
        if (nonEmptyEnvValue($key) !== null) {
            fwrite(STDERR, "Refusing E2E reset with custom Laravel config cache path; unset {$key}.\n");
            exit(1);
        }
    }

    if (is_file($root.'/bootstrap/cache/config.php')) {
        fwrite(STDERR, "Refusing E2E reset with cached Laravel config; run php artisan config:clear first.\n");
        exit(1);
    }
}

function rejectProcessAppEnv(): void
{
    $appEnv = nonEmptyEnvValue('APP_ENV');

    if ($appEnv !== null && $appEnv !== 'e2e') {
        fwrite(STDERR, "Refusing E2E reset: APP_ENV must be exactly e2e.\n");
        exit(1);
    }
}

function rejectProcessDatabaseUrl(): void
{
    if (nonEmptyEnvValue('DB_URL') !== null) {
        fwrite(STDERR, 'Refusing E2E reset: DB_URL must be unset; use DB_DATABASE='.E2E_DATABASE.".\n");
        exit(1);
    }
}

function guardE2eApp($app): void
{
    if ($app->environment() !== 'e2e') {
        fwrite(STDERR, "Refusing E2E reset: APP_ENV must be exactly e2e.\n");
        exit(1);
    }
}

/** @return array{0: string, 1: string} */
function guardE2eDatabase(): array
{
    $connectionName = (string) config('database.default');
    $connection = config("database.connections.{$connectionName}");

    if (! is_array($connection)) {
        fwrite(STDERR, "Refusing E2E reset: default DB connection is invalid.\n");
        exit(1);
    }

    if (nonEmptyConfigValue($connection['url'] ?? null) !== null || nonEmptyEnvValue('DB_URL') !== null) {
        fwrite(STDERR, 'Refusing E2E reset: DB_URL must be unset; use DB_DATABASE='.E2E_DATABASE.".\n");
        exit(1);
    }

    $effective = (new ConfigurationUrlParser())->parseConfiguration($connection);
    $database = (string) ($effective['database'] ?? '');

    if ($database !== E2E_DATABASE) {
        fwrite(STDERR, 'Refusing E2E reset: effective database must be '.E2E_DATABASE.".\n");
        exit(1);
    }

    return [$connectionName, $database];
}

function nonEmptyEnvValue(string $key): ?string
{
    foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $value) {
        if (nonEmptyConfigValue($value) !== null) {
            return (string) $value;
        }
    }

    return null;
}

function nonEmptyConfigValue(mixed $value): ?string
{
    if ($value === false || $value === null) {
        return null;
    }

    $value = trim((string) $value);

    return $value === '' ? null : $value;
}
