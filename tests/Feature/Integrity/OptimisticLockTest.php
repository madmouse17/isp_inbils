<?php

namespace Tests\Feature\Integrity;

use App\Models\Core\Company;
use App\Models\Core\Vehicle;
use App\Support\OptimisticLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * Mechanism (b) of integrity-hardening: a form sends the version it was rendered from and the write compares it with
 * the row it holds under lockForUpdate. The vehicle stands in for the nine adopting models; the race and the lock
 * probe also exercise the test infrastructure (the HTTP-exception worker result and waitsForRowLock()).
 */
class OptimisticLockTest extends TestCase
{
    use BuildsCompanyGroup;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    /** The adoption pattern of design §4.2: lock the row, compare, write, all in one transaction. */
    private const SAVE = <<<'PHP'
        return Illuminate\Support\Facades\DB::transaction(function () use ($args) {
            $locked = App\Models\Core\Vehicle::query()->lockForUpdate()->findOrFail($args['vehicle_id']);
            App\Support\OptimisticLock::assert($locked, $args['expected']);
            usleep(300000); // holds the row, so the second saver queues behind the first
            $locked->update(['notes' => $args['notes']]);

            return $args['notes'];
        });
    PHP;

    public function test_the_version_is_updated_at_in_utc_with_milliseconds(): void
    {
        $vehicle = new Vehicle();
        $this->assertNull(OptimisticLock::version($vehicle));

        $vehicle->updated_at = Carbon::parse('2026-10-18 12:34:56');

        $this->assertSame('2026-10-18T12:34:56.000Z', OptimisticLock::version($vehicle));
    }

    public function test_assert_passes_when_the_row_still_has_the_version_the_form_was_rendered_from(): void
    {
        $vehicle = $this->vehicleOf(Company::factory()->create(['is_active' => true]));

        OptimisticLock::assert($vehicle->fresh(), OptimisticLock::version($vehicle));

        $this->addToAssertionCount(1);
    }

    public function test_assert_throws_a_409_when_the_row_changed_since_the_form_was_rendered(): void
    {
        $vehicle = $this->vehicleOf(Company::factory()->create(['is_active' => true]));
        $rendered = OptimisticLock::version($vehicle);

        $vehicle->touch();

        try {
            OptimisticLock::assert($vehicle->fresh(), $rendered);
            $this->fail('A stale version must be refused.');
        } catch (ConflictHttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame(OptimisticLock::MESSAGE, $e->getMessage());
            $this->assertSame('Data diubah pengguna lain; muat ulang.', $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('submittedVersions')]
    public function test_rules_accept_only_the_canonical_utc_string(array $payload, bool $passes): void
    {
        $validator = Validator::make($payload, [OptimisticLock::FIELD => OptimisticLock::rules()]);

        $this->assertSame($passes, $validator->passes(), json_encode($validator->errors()->all()));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: bool}> */
    public static function submittedVersions(): array
    {
        return [
            'the canonical string' => [['expected_updated_at' => '2026-10-18T12:34:56.789Z'], true],
            'a missing field' => [[], false],
            'null' => [['expected_updated_at' => null], false],
            'an empty string' => [['expected_updated_at' => ''], false],
            'whole seconds' => [['expected_updated_at' => '2026-10-18T12:34:56Z'], false],
            'two fraction digits' => [['expected_updated_at' => '2026-10-18T12:34:56.78Z'], false],
            'a space instead of the T' => [['expected_updated_at' => '2026-10-18 12:34:56.789'], false],
            'an offset instead of the Z' => [['expected_updated_at' => '2026-10-18T12:34:56.789+07:00'], false],
            'a date only' => [['expected_updated_at' => '2026-10-18'], false],
            'a number' => [['expected_updated_at' => 20261018], false],
        ];
    }

    public function test_a_stale_save_over_http_is_the_shared_conflict_and_changes_nothing(): void
    {
        $this->buildGroup();
        $admin = $this->groupUser($this->head);
        $vehicle = $this->vehicleOf($this->head);
        $rendered = OptimisticLock::version($vehicle);

        // What an adopting controller does: validate the field, then lock, compare and write in one transaction.
        Route::middleware('web')->put('_probe/vehicles/{id}', function (Request $request, int $id) {
            $request->validate([OptimisticLock::FIELD => OptimisticLock::rules()]);

            DB::transaction(function () use ($request, $id): void {
                $locked = Vehicle::query()->lockForUpdate()->findOrFail($id);
                OptimisticLock::assert($locked, $request->string(OptimisticLock::FIELD)->toString());
                $locked->update(['notes' => $request->input('notes')]);
            });

            return response()->noContent();
        });

        $this->actingAs($admin);

        $this->putJson("/_probe/vehicles/{$vehicle->id}", ['notes' => 'no version'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(OptimisticLock::FIELD);

        $this->putJson("/_probe/vehicles/{$vehicle->id}", ['notes' => 'first save', OptimisticLock::FIELD => $rendered])->assertNoContent();

        $stale = ['notes' => 'second save', OptimisticLock::FIELD => $rendered];

        $this->putJson("/_probe/vehicles/{$vehicle->id}", $stale)
            ->assertStatus(409)
            ->assertExactJson(['message' => OptimisticLock::MESSAGE, 'error' => 'conflict']);

        $this->withHeader('X-Inertia', 'true')->put("/_probe/vehicles/{$vehicle->id}", $stale)
            ->assertStatus(303)
            ->assertSessionHasErrors(['conflict' => OptimisticLock::MESSAGE])
            ->assertSessionHas('error', OptimisticLock::MESSAGE);

        $this->assertSame('first save', Vehicle::withoutCompany()->findOrFail($vehicle->id)->notes);
    }

    public function test_two_saves_racing_from_one_version_let_exactly_one_win(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $vehicle = $this->vehicleOf($company);
        $rendered = OptimisticLock::version($vehicle);
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $company->id, 'vehicle_id' => $vehicle->id, 'expected' => $rendered, 'notes' => 'from A'],
                ['company_id' => $company->id, 'vehicle_id' => $vehicle->id, 'expected' => $rendered, 'notes' => 'from B'],
            ], self::SAVE);

            $winners = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
            $losers = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));

            $this->assertCount(1, $winners, json_encode($results));
            $this->assertCount(1, $losers, json_encode($results));
            $this->assertSame(['ok' => false, 'status' => 409, 'message' => OptimisticLock::MESSAGE], $losers[0]);
            $this->assertSame($winners[0]['result'], Vehicle::withoutCompany()->findOrFail($vehicle->id)->notes);
        } finally {
            $this->purgeCompany($company->id);
        }
    }

    public function test_the_lock_probe_tells_a_locking_read_from_a_plain_one_and_cleans_up_after_itself(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['company_id' => $company->id]);
        $this->commitFixture();
        $timeoutBefore = DB::scalar('SELECT @@SESSION.innodb_lock_wait_timeout');

        try {
            $this->assertTrue($this->waitsForRowLock(
                'vehicles',
                $vehicle->id,
                fn () => DB::transaction(fn () => Vehicle::withoutCompany()->lockForUpdate()->findOrFail($vehicle->id)),
            ));
            $this->assertFalse($this->waitsForRowLock(
                'vehicles',
                $vehicle->id,
                fn () => Vehicle::withoutCompany()->findOrFail($vehicle->id),
            ));

            try {
                $this->waitsForRowLock('vehicles', $vehicle->id, fn () => throw new LogicException('boom'));
                $this->fail('An exception other than a lock wait must propagate.');
            } catch (LogicException $e) {
                $this->assertSame('boom', $e->getMessage());
            }

            $this->assertSame($timeoutBefore, DB::scalar('SELECT @@SESSION.innodb_lock_wait_timeout'));
            $this->assertArrayNotHasKey('mysql_peer', DB::getConnections());
            $this->assertSame($vehicle->id, DB::transaction(fn () => Vehicle::withoutCompany()->lockForUpdate()->findOrFail($vehicle->id))->id);
        } finally {
            $this->purgeCompany($company->id);
        }
    }

    /** A vehicle whose version is a minute old, so that any write moves it to another second. */
    private function vehicleOf(Company $company): Vehicle
    {
        return Vehicle::factory()->create(['company_id' => $company->id, 'notes' => 'start', 'updated_at' => now()->subMinute()]);
    }
}
