<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\NetworkAssetInstallation;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Services\SpkService;
use PHPUnit\Framework\ExpectationFailedException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

function tempnam(string $directory, string $prefix): string|false
{
    if ($prefix === 'spk-lock-' && SerializedAssetConcurrencySmokeTest::$failSignalPreparation) {
        return false;
    }

    return \tempnam($directory, $prefix);
}

/**
 * P0-C smoke: serialized asset concurrency and side effects.
 *
 * Covers:
 *  - lockForUpdate prevents race on completion
 *  - duplicate asset assignment is rejected
 *  - multiple serialized assets installed in one SPK
 *  - orphan prevention (asset must be available)
 *  - subscription ONT asset linkage
 */
class SerializedAssetConcurrencySmokeTest extends TestCase
{
    use RefreshDatabase;

    public static bool $failSignalPreparation = false;

    /** @test */
    public function lock_for_update_prevents_concurrent_completion(): void
    {
        [$company, $user, $approver, $location, $customer] = $this->scope();
        $product = $this->product($company);
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);
        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => 'available',
            'serial_number' => 'SN-RACE-001',
        ]);
        $workOrder = $this->workOrder($company, $user, $location, $customer, $subscription);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'network_asset_id' => $asset->id,
        ]);
        $this->evidence($workOrder, $user);

        $servicePackage = DB::table('service_packages')->where('id', $subscription->service_package_id)->first();
        $subscriptionAddress = DB::table('customer_addresses')->where('id', $subscription->installation_address_id)->first();

        $fixtureIds = [
            'company' => $company->id,
            'users' => [$user->id, $approver->id],
            'location' => $location->id,
            'customer' => $customer->id,
            'subscription' => $subscription->id,
            'address' => $subscription->installation_address_id,
            'address_customer' => $subscriptionAddress?->customer_id,
            'service_package' => $subscription->service_package_id,
            'bandwidth_profile' => $servicePackage?->bandwidth_profile_id,
            'speed_profile' => $servicePackage?->speed_profile_id,
            'sla_tier' => $servicePackage?->sla_tier_id,
            'product' => $product->id,
            'category' => $product->category_id,
            'unit' => $product->unit_id,
            'asset' => $asset->id,
            'work_order' => $workOrder->id,
            'work_order_item' => WorkOrderItem::query()->where('work_order_id', $workOrder->id)->value('id'),
        ];

        $signal = null;
        $workers = [];

        // Commit the fixture so independent worker connections can observe the same row.
        DB::commit();

        try {
            $signal = tempnam(sys_get_temp_dir(), 'spk-lock-');
            $this->assertNotFalse($signal);
            file_put_contents($signal, '');
            $workerArgs = [
                'company_id' => $company->id,
                'work_order_id' => $workOrder->id,
                'user_id' => $approver->id,
                'signal' => $signal,
            ];
            $workers[] = $this->startWorker($workerArgs, true);
            $this->waitForSignal($signal, $workers[0]);
            $workers[] = $this->startWorker($workerArgs, false);
            $winnerResult = $this->finishWorker($workers[0]);
            $loserResult = $this->finishWorker($workers[1]);

            $this->assertSame(0, $winnerResult['exit_code'], $winnerResult['output']);
            $this->assertSame(0, $loserResult['exit_code'], $loserResult['output']);
            $this->assertStringContainsString('winner:completed', $winnerResult['output']);
            $this->assertStringContainsString('loser:422', $loserResult['output']);

            $locked = WorkOrder::withoutCompany()->findOrFail($workOrder->id);
            $this->assertSame('completed', $locked->status);
            $this->assertDatabaseHas('network_assets', ['id' => $asset->id, 'status' => 'installed']);
            $this->assertDatabaseCount('network_asset_installations', 1);
        } finally {
            foreach ($workers as $worker) {
                $this->stopWorker($worker);
            }
            if (is_string($signal)) {
                @unlink($signal);
            }
            $this->cleanupCommittedFixture($fixtureIds);
        }

        $this->assertDatabaseMissing('network_asset_installations', ['network_asset_id' => $fixtureIds['asset']]);
        $this->assertDatabaseMissing('work_order_items', ['id' => $fixtureIds['work_order_item']]);
        $this->assertDatabaseMissing('work_orders', ['id' => $fixtureIds['work_order']]);
        $this->assertDatabaseMissing('network_assets', ['id' => $fixtureIds['asset']]);
        $this->assertDatabaseMissing('service_subscriptions', ['id' => $fixtureIds['subscription']]);
        $this->assertDatabaseMissing('customers', ['id' => $fixtureIds['customer']]);
        $this->assertDatabaseMissing('locations', ['id' => $fixtureIds['location']]);
        $this->assertDatabaseMissing('products', ['id' => $fixtureIds['product']]);
        $this->assertDatabaseMissing('companies', ['id' => $fixtureIds['company']]);
    }

    /** @test */
    public function signal_preparation_failure_still_cleans_committed_fixture(): void
    {
        [$company, $user, $approver, $location, $customer] = $this->scope();
        $product = $this->product($company);
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);
        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => 'available',
            'serial_number' => 'SN-SIGNAL-FAIL-001',
        ]);
        $workOrder = $this->workOrder($company, $user, $location, $customer, $subscription);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'network_asset_id' => $asset->id,
        ]);
        $this->evidence($workOrder, $user);

        $servicePackage = DB::table('service_packages')->where('id', $subscription->service_package_id)->first();
        $subscriptionAddress = DB::table('customer_addresses')->where('id', $subscription->installation_address_id)->first();
        $fixtureIds = [
            'company' => $company->id,
            'users' => [$user->id, $approver->id],
            'location' => $location->id,
            'customer' => $customer->id,
            'subscription' => $subscription->id,
            'address' => $subscription->installation_address_id,
            'address_customer' => $subscriptionAddress?->customer_id,
            'service_package' => $subscription->service_package_id,
            'bandwidth_profile' => $servicePackage?->bandwidth_profile_id,
            'speed_profile' => $servicePackage?->speed_profile_id,
            'sla_tier' => $servicePackage?->sla_tier_id,
            'product' => $product->id,
            'category' => $product->category_id,
            'unit' => $product->unit_id,
            'asset' => $asset->id,
            'work_order' => $workOrder->id,
            'work_order_item' => WorkOrderItem::query()->where('work_order_id', $workOrder->id)->value('id'),
        ];

        $signal = null;
        $preparationFailed = false;
        DB::commit();

        try {
            self::$failSignalPreparation = true;
            $signal = tempnam(sys_get_temp_dir(), 'spk-lock-');
            $this->assertNotFalse($signal);
        } catch (ExpectationFailedException) {
            $preparationFailed = true;
        } finally {
            self::$failSignalPreparation = false;
            if (is_string($signal)) {
                @unlink($signal);
            }
            $this->cleanupCommittedFixture($fixtureIds);
        }

        $this->assertTrue($preparationFailed, 'Signal preparation must fail after the fixture commit.');
        $this->assertDatabaseMissing('network_asset_installations', ['network_asset_id' => $fixtureIds['asset']]);
        $this->assertDatabaseMissing('work_order_items', ['id' => $fixtureIds['work_order_item']]);
        $this->assertDatabaseMissing('work_orders', ['id' => $fixtureIds['work_order']]);
        $this->assertDatabaseMissing('network_assets', ['id' => $fixtureIds['asset']]);
        $this->assertDatabaseMissing('service_subscriptions', ['id' => $fixtureIds['subscription']]);
        $this->assertDatabaseMissing('customer_addresses', ['id' => $fixtureIds['address']]);
        $this->assertDatabaseMissing('products', ['id' => $fixtureIds['product']]);
        $this->assertDatabaseMissing('categories', ['id' => $fixtureIds['category']]);
        $this->assertDatabaseMissing('units', ['id' => $fixtureIds['unit']]);
        $this->assertDatabaseMissing('service_packages', ['id' => $fixtureIds['service_package']]);
        $this->assertDatabaseMissing('bandwidth_profiles', ['id' => $fixtureIds['bandwidth_profile']]);
        $this->assertDatabaseMissing('speed_profiles', ['id' => $fixtureIds['speed_profile']]);
        $this->assertDatabaseMissing('sla_tiers', ['id' => $fixtureIds['sla_tier']]);
        $this->assertDatabaseMissing('customers', ['id' => $fixtureIds['customer']]);
        $this->assertDatabaseMissing('locations', ['id' => $fixtureIds['location']]);
        $this->assertDatabaseMissing('companies', ['id' => $fixtureIds['company']]);
    }

    /** @param array{company_id: int, work_order_id: int, user_id: int, signal: string} $args */
    private function startWorker(array $args, bool $winner): array
    {
        $args['winner'] = $winner;
        $payload = tempnam(sys_get_temp_dir(), 'spk-worker-');
        $this->assertNotFalse($payload);
        file_put_contents($payload, json_encode($args, JSON_THROW_ON_ERROR));
        $script = <<<'PHP'
$args = json_decode(file_get_contents(getenv('SPK_WORKER_PAYLOAD')), true, 512, JSON_THROW_ON_ERROR);
require getcwd().'/vendor/autoload.php';
Tests\TestCase::guardPreBootstrapEnvironment();
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Auth::loginUsingId($args['user_id']);
App\Services\Core\CompanyService::useCompanyForTests((int) $args['company_id']);
$wo = Modules\SPK\Models\WorkOrder::withoutCompany()->findOrFail($args['work_order_id']);
if ($args['winner']) {
    Illuminate\Support\Facades\DB::beginTransaction();
    Modules\SPK\Models\WorkOrder::withoutCompany()->lockForUpdate()->findOrFail($wo->id);
    file_put_contents($args['signal'], 'lock-held');
    usleep(250000);
}
try {
    Modules\SPK\Services\SpkService::approve($wo);
    if ($args['winner']) { Illuminate\Support\Facades\DB::commit(); }
    fwrite(STDOUT, $args['winner'] ? 'winner:completed lock-held' : 'unexpected-success');
} catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
    if ($args['winner']) { Illuminate\Support\Facades\DB::rollBack(); }
    if ($e->getStatusCode() !== 422) { fwrite(STDERR, 'unexpected-status:'.$e->getStatusCode()); exit(1); }
    fwrite(STDOUT, 'loser:422');
}
PHP;
        $workerScript = tempnam(sys_get_temp_dir(), 'spk-script-');
        if ($workerScript === false) {
            @unlink($payload);
            $this->fail('Unable to create the worker script.');
        }
        file_put_contents($workerScript, '<?php'.PHP_EOL.$script);
        $command = PHP_BINARY.' '.escapeshellarg($workerScript);
        $pipes = [];
        $environment = self::isolatedProcessEnvironment([
            'SPK_WORKER_PAYLOAD' => $payload,
        ]);
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, getcwd(), $environment);
        if (! is_resource($process)) {
            @unlink($payload);
            @unlink($workerScript);
            $this->fail('Unable to start the concurrency worker.');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        foreach ([1, 2] as $pipe) {
            if (isset($pipes[$pipe]) && is_resource($pipes[$pipe])) {
                stream_set_blocking($pipes[$pipe], false);
            }
        }

        return ['process' => $process, 'pipes' => $pipes, 'payload' => $payload, 'script' => $workerScript];
    }

    /** @param array{process: resource, pipes: array<int, resource>} $worker */
    private function waitForSignal(string $signal, array $worker): void
    {
        $deadline = microtime(true) + 5;
        while (trim((string) file_get_contents($signal)) !== 'lock-held') {
            $status = proc_get_status($worker['process']);
            if (! $status['running']) {
                $output = $this->workerOutput($worker);
                $this->fail('Concurrency worker exited before lock signal ('.$status['exitcode'].'): '.$output);
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Concurrency worker did not report its held row lock within 5 seconds.');
            }
            usleep(10000);
        }
    }

    /** @param array{process: resource, pipes: array<int, resource>, payload: string, script: string} $worker */
    private function finishWorker(array $worker): array
    {
        $deadline = microtime(true) + 10;
        $output = '';
        $exitCode = null;
        $finished = false;

        try {
            do {
                $status = proc_get_status($worker['process']);
                if (! $status['running']) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $this->fail('Concurrency worker exceeded its 10-second deadline.');
                }
                usleep(10000);
            } while (true);

            $output = $this->workerOutput($worker);
            $this->closeWorkerPipes($worker);
            @unlink($worker['payload']);
            @unlink($worker['script']);
            if (is_resource($worker['process'])) {
                $exitCode = proc_close($worker['process']);
            }
            $finished = true;
        } finally {
            if (! $finished) {
                $this->stopWorker($worker);
            }
        }

        return ['exit_code' => $exitCode, 'output' => $output];
    }

    /** @param array{process: resource, pipes: array<int, resource>, payload: string, script: string} $worker */
    private function stopWorker(array $worker): void
    {
        if (is_resource($worker['process'])) {
            $status = proc_get_status($worker['process']);
            if (($status['running'] ?? false) === true) {
                proc_terminate($worker['process']);
            }
        }

        $this->closeWorkerPipes($worker);
        if (is_resource($worker['process'])) {
            proc_close($worker['process']);
        }
        @unlink($worker['payload']);
        @unlink($worker['script']);
    }

    /** @param array{pipes: array<int, resource>} $worker */
    private function workerOutput(array $worker): string
    {
        $output = '';
        foreach ([1, 2] as $pipe) {
            if (isset($worker['pipes'][$pipe]) && is_resource($worker['pipes'][$pipe])) {
                $output .= (string) stream_get_contents($worker['pipes'][$pipe]);
            }
        }

        return $output;
    }

    /** @param array{pipes: array<int, resource>} $worker */
    private function closeWorkerPipes(array $worker): void
    {
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    /** @param array<string, int|null|array<int, int|null>> $fixtureIds */
    private function cleanupCommittedFixture(array $fixtureIds): void
    {
        DB::transaction(function () use ($fixtureIds): void {
            $workOrderId = $fixtureIds['work_order'];
            $workOrderItemId = $fixtureIds['work_order_item'];
            $assetId = $fixtureIds['asset'];
            $companyId = $fixtureIds['company'];
            $productIds = array_values(array_filter([$fixtureIds['product']]));
            $locationIds = array_values(array_filter([$fixtureIds['location']]));
            $userIds = array_values(array_filter($fixtureIds['users']));

            Media::query()
                ->where(function ($query) use ($workOrderId, $assetId): void {
                    $query->where(function ($workOrderMedia) use ($workOrderId): void {
                        $workOrderMedia->where('model_type', WorkOrder::class)
                            ->where('model_id', $workOrderId);
                    })->orWhere(function ($assetMedia) use ($assetId): void {
                        $assetMedia->where('model_type', NetworkAsset::class)
                            ->where('model_id', $assetId);
                    });
                })
                ->get()
                ->each(fn (Media $media): bool => $media->delete());

            DB::table('activity_log')
                ->where('causer_type', User::class)
                ->whereIn('causer_id', $userIds)
                ->delete();
            DB::table('activity_log')
                ->where(function ($query) use ($workOrderId, $assetId): void {
                    $query->where(function ($workOrderActivity) use ($workOrderId): void {
                        $workOrderActivity->where('subject_type', WorkOrder::class)
                            ->where('subject_id', $workOrderId);
                    })->orWhere(function ($assetActivity) use ($assetId): void {
                        $assetActivity->where('subject_type', NetworkAsset::class)
                            ->where('subject_id', $assetId);
                    });
                })
                ->delete();
            if (Schema::hasTable('work_order_evidence')) {
                DB::table('work_order_evidence')->where('work_order_id', $workOrderId)->delete();
            }
            DB::table('work_order_assignments')->where('work_order_id', $workOrderId)->delete();

            $invoiceIds = DB::table('invoices')
                ->where('work_order_id', $workOrderId)
                ->pluck('id');
            DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->delete();
            DB::table('payments')->whereIn('invoice_id', $invoiceIds)->delete();
            DB::table('invoices')->whereIn('id', $invoiceIds)->delete();

            DB::table('stock_movements')
                ->where(function ($query) use ($productIds, $locationIds, $workOrderItemId): void {
                    $query->where(function ($productMovement) use ($productIds, $locationIds): void {
                        $productMovement->whereIn('product_id', $productIds)
                            ->where(function ($locations) use ($locationIds): void {
                                $locations->whereIn('from_location_id', $locationIds)
                                    ->orWhereIn('to_location_id', $locationIds);
                            });
                    })->orWhere(function ($reference) use ($workOrderItemId): void {
                        $reference->where('reference_id', $workOrderItemId)
                            ->where('reference_type', WorkOrderItem::class);
                    });
                })
                ->delete();
            DB::table('stocks')
                ->whereIn('product_id', $productIds)
                ->whereIn('location_id', $locationIds)
                ->delete();

            DB::table('network_asset_installations')->where('network_asset_id', $assetId)->delete();
            DB::table('work_order_items')->where('work_order_id', $workOrderId)->delete();
            DB::table('network_assets')->where('id', $assetId)->delete();
            DB::table('work_orders')->where('id', $workOrderId)->delete();
            // K18: assigning or approving an installation makes the PPPoE account of the subscription.
            DB::table('network_accounts')->where('subscription_id', $fixtureIds['subscription'])->delete();
            DB::table('service_subscriptions')->where('id', $fixtureIds['subscription'])->delete();
            DB::table('customer_addresses')->where('id', $fixtureIds['address'])->delete();
            DB::table('products')->whereIn('id', $productIds)->delete();
            DB::table('categories')->where('id', $fixtureIds['category'])->delete();
            DB::table('units')->where('id', $fixtureIds['unit'])->delete();
            DB::table('service_packages')->where('id', $fixtureIds['service_package'])->delete();
            DB::table('service_package_categories')->where('company_id', $companyId)->delete();
            DB::table('bandwidth_profiles')->where('id', $fixtureIds['bandwidth_profile'])->delete();
            DB::table('speed_profiles')->where('id', $fixtureIds['speed_profile'])->delete();
            DB::table('sla_tiers')->where('id', $fixtureIds['sla_tier'])->delete();
            DB::table('customers')->where('id', $fixtureIds['customer'])->delete();
            if ($fixtureIds['address_customer'] !== null) {
                DB::table('customers')->where('id', $fixtureIds['address_customer'])->delete();
            }
            DB::table('locations')->where('id', $fixtureIds['location'])->delete();
            DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
            DB::table('number_sequences')->where('company_id', $companyId)->delete();
            DB::table('companies')->where('id', $companyId)->delete();
        });
    }

    /** @test */
    public function duplicate_asset_assignment_is_rejected(): void
    {
        [$company, $user, $approver, $location, $customer] = $this->scope();
        $product = $this->product($company);
        $subscription1 = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);
        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => 'available',
            'serial_number' => 'SN-DUP-001',
        ]);

        // First SPK completes and installs the asset
        $wo1 = $this->workOrder($company, $user, $location, $customer, $subscription1);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $wo1->id,
            'product_id' => $product->id,
            'network_asset_id' => $asset->id,
        ]);
        $this->evidence($wo1, $user);
        SpkService::approve($wo1);
        $this->assertSame('installed', $asset->fresh()->status);

        // Second SPK tries to use the same asset
        $subscription2 = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);
        $wo2 = $this->workOrder($company, $user, $location, $customer, $subscription2);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $wo2->id,
            'product_id' => $product->id,
            'network_asset_id' => $asset->id,
        ]);
        $this->evidence($wo2, $user);

        try {
            SpkService::approve($wo2);
            $this->fail('Duplicate asset assignment was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('work_orders', ['id' => $wo2->id, 'status' => 'waiting_review']);
        $this->assertDatabaseCount('network_asset_installations', 1);
    }

    /** @test */
    public function multiple_serialized_assets_can_be_installed_in_one_spk(): void
    {
        [$company, $user, $approver, $location, $customer] = $this->scope();
        $product1 = $this->product($company, 'SMK-PRD-M1-');
        $product2 = $this->product($company, 'SMK-PRD-M2-');
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);

        $asset1 = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'product_id' => $product1->id,
            'status' => 'available',
            'serial_number' => 'SN-MULTI-001',
        ]);
        $asset2 = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'product_id' => $product2->id,
            'status' => 'available',
            'serial_number' => 'SN-MULTI-002',
        ]);

        $workOrder = $this->workOrder($company, $user, $location, $customer, $subscription);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product1->id,
            'network_asset_id' => $asset1->id,
        ]);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product2->id,
            'network_asset_id' => $asset2->id,
        ]);
        $this->evidence($workOrder, $user);

        SpkService::approve($workOrder);

        $this->assertSame('installed', $asset1->fresh()->status);
        $this->assertSame('installed', $asset2->fresh()->status);
        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'completed']);
        $this->assertDatabaseCount('network_asset_installations', 2);

        // First asset should be linked as ONT
        $this->assertEquals($asset1->id, $subscription->fresh()->ont_asset_id);
    }

    /** @test */
    public function asset_must_be_available_to_install(): void
    {
        [$company, $user, $approver, $location, $customer] = $this->scope();
        $product = $this->product($company);
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);

        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => 'retired',
            'serial_number' => 'SN-ORPHAN-001',
        ]);

        $workOrder = $this->workOrder($company, $user, $location, $customer, $subscription);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'network_asset_id' => $asset->id,
        ]);
        $this->evidence($workOrder, $user);

        try {
            SpkService::approve($workOrder);
            $this->fail('Non-available asset was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('network_assets', ['id' => $asset->id, 'status' => 'retired']);
        $this->assertDatabaseMissing('network_asset_installations', ['network_asset_id' => $asset->id]);
    }

    /** @test */
    public function serialized_item_without_network_asset_id_is_rejected(): void
    {
        [$company, $user, $approver, $location, $customer] = $this->scope();
        $product = $this->product($company);
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);

        $workOrder = $this->workOrder($company, $user, $location, $customer, $subscription);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'network_asset_id' => null,
        ]);
        $this->evidence($workOrder, $user);

        try {
            SpkService::approve($workOrder);
            $this->fail('Serialized item without asset selection was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'waiting_review']);
        $this->assertDatabaseMissing('network_asset_installations', ['spk_id' => $workOrder->id]);
    }

    /** @test */
    public function asset_with_active_installation_is_rejected(): void
    {
        [$company, $user, $approver, $location, $customer] = $this->scope();
        $product = $this->product($company);
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);

        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => 'available',
            'serial_number' => 'SN-ACTIVE-001',
        ]);

        // Pre-seed an active installation
        NetworkAssetInstallation::create([
            'company_id' => $company->id,
            'network_asset_id' => $asset->id,
            'location_id' => $location->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'spk_id' => 999999,
            'installed_by' => $user->id,
            'installed_at' => now(),
        ]);

        $workOrder = $this->workOrder($company, $user, $location, $customer, $subscription);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'network_asset_id' => $asset->id,
        ]);
        $this->evidence($workOrder, $user);

        try {
            SpkService::approve($workOrder);
            $this->fail('Asset with active installation was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'waiting_review']);
        $this->assertDatabaseCount('network_asset_installations', 1);
    }

    // ── Helpers (mirror DeterministicAssetProvisioningTest) ─────────────

    private function scope(): array
    {
        Permission::firstOrCreate(['name' => 'spk.approve', 'guard_name' => 'web']);
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);
        $approver->givePermissionTo('spk.approve');
        $location = Location::create([
            'company_id' => $company->id,
            'code' => 'LOC-SMK-'.fake()->unique()->numberBetween(1, 9999),
            'name' => 'SMK Test Location',
            'type' => 'site',
            'path' => 'SMK Test Location',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $this->actingAs($approver);

        return [$company, $user, $approver, $location, $customer];
    }

    private function product(Company $company, string $prefix = 'SMK-PRD-'): Product
    {
        $uid = fake()->unique()->numberBetween(1, 9999);
        $unit = Unit::create([
            'company_id' => $company->id,
            'name' => 'Piece-'.$uid,
            'symbol' => 'pcs-'.$uid,
        ]);
        $category = Category::create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'name' => 'SMK Test Category-'.$uid,
            'code' => 'SMK-CAT-'.$uid,
            'is_active' => true,
        ]);

        return Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => $prefix.$uid,
            'name' => 'SMK Test Product',
            'type' => 'asset',
            'track_stock' => true,
            'sell_price' => 100_000,
            'cost_price' => 50_000,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }

    private function workOrder(Company $company, User $user, Location $location, Customer $customer, ServiceSubscription $subscription): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'type' => 'installation',
            'status' => 'waiting_review',
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => $location->id,
            'created_by' => $user->id,
        ]);
    }

    private function evidence(WorkOrder $workOrder, User $user): void
    {
        $workOrder->addMediaFromString('test')
            ->usingFileName('test.jpg')
            ->withCustomProperties([
                'company_id' => $workOrder->company_id,
                'type' => 'photo',
                'uploaded_by' => $user->id,
            ])
            ->toMediaCollection('evidence', 'public');
    }
}
