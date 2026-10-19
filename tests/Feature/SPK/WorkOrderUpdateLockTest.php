<?php

namespace Tests\Feature\SPK;

use App\Models\User;
use App\Support\OptimisticLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * An SPK is edited and deleted under its row lock: a save made from an older copy is refused, and a request that waited
 * for the SPK to move on finds it moved and changes nothing.
 */
class WorkOrderUpdateLockTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    private const NOT_EDITABLE = 'SPK hanya bisa diubah saat berstatus draf atau menunggu penugasan.';

    private const NOT_DELETABLE = 'SPK hanya bisa dihapus saat berstatus draf atau dibatalkan.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
    }

    /** @return array<string, array{0: string}> */
    public static function statusesPastPlanning(): array
    {
        return ['assigned' => ['assigned'], 'in progress' => ['in_progress'], 'waiting for review' => ['waiting_review'], 'completed' => ['completed'], 'rejected' => ['rejected'], 'cancelled' => ['cancelled']];
    }

    /** An SPK last saved five minutes ago, so a save made now is a different version of it (versions keep whole seconds). */
    private function workOrder(string $status = 'generated'): WorkOrder
    {
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $this->admin->company_id,
            'type' => 'maintenance',
            'status' => $status,
            'title' => 'as rendered',
            'created_by' => $this->admin->id,
        ]);
        DB::table('work_orders')->where('id', $workOrder->id)->update(['updated_at' => now()->subMinutes(5)]);

        return $workOrder->fresh();
    }

    public function test_an_spk_save_from_an_older_copy_is_refused(): void
    {
        $workOrder = $this->workOrder();
        $rendered = OptimisticLock::version($workOrder);

        $this->actingAs($this->admin)
            ->put(route('admin.spk.update', $workOrder), ['title' => 'first save', OptimisticLock::FIELD => $rendered])
            ->assertRedirect(route('admin.spk.index'))
            ->assertSessionHas('success', 'SPK berhasil diubah.');

        $this->putJson(route('admin.spk.update', $workOrder), ['title' => 'second save', OptimisticLock::FIELD => $rendered])
            ->assertStatus(409)
            ->assertJsonPath('error', 'conflict')
            ->assertJsonPath('message', OptimisticLock::MESSAGE);

        // A page visit (X-Inertia) gets the redirect back with the message the toast and the banner show.
        $this->withHeaders(['X-Inertia' => 'true'])
            ->put(route('admin.spk.update', $workOrder), ['title' => 'third save', OptimisticLock::FIELD => $rendered])
            ->assertStatus(303)
            ->assertSessionHasErrors(['conflict' => OptimisticLock::MESSAGE]);

        $this->assertSame('first save', $workOrder->fresh()->title, 'the refused saves changed nothing');
    }

    public function test_a_save_needs_the_version_the_form_was_rendered_from(): void
    {
        $workOrder = $this->workOrder();

        $this->actingAs($this->admin)
            ->putJson(route('admin.spk.update', $workOrder), ['title' => 'no version'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(OptimisticLock::FIELD);

        $this->putJson(route('admin.spk.update', $workOrder), ['title' => 'bad version', OptimisticLock::FIELD => '2026-10-18'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(OptimisticLock::FIELD);

        $this->assertSame('as rendered', $workOrder->fresh()->title);
    }

    public function test_a_draft_and_a_generated_spk_are_saved_and_a_draft_or_cancelled_one_is_deleted(): void
    {
        $draft = $this->workOrder('draft');
        $generated = $this->workOrder('generated');
        $cancelled = $this->workOrder('cancelled');

        $this->actingAs($this->admin)
            ->put(route('admin.spk.update', $draft), ['title' => 'draft saved', OptimisticLock::FIELD => OptimisticLock::version($draft)])
            ->assertRedirect(route('admin.spk.index'));
        $this->put(route('admin.spk.update', $generated), ['title' => 'generated saved', OptimisticLock::FIELD => OptimisticLock::version($generated)])
            ->assertRedirect(route('admin.spk.index'));
        $this->delete(route('admin.spk.destroy', $draft))->assertRedirect()->assertSessionHas('success', 'SPK berhasil dihapus.');
        $this->delete(route('admin.spk.destroy', $cancelled))->assertRedirect();

        $this->assertSame('generated saved', $generated->fresh()->title);
        $this->assertSoftDeleted($draft);
        $this->assertSoftDeleted($cancelled);
    }

    #[DataProvider('statusesPastPlanning')]
    public function test_an_spk_past_planning_is_neither_saved_nor_deleted(string $status): void
    {
        $workOrder = $this->workOrder($status);

        $this->actingAs($this->admin)
            ->putJson(route('admin.spk.update', $workOrder), ['title' => 'late edit', OptimisticLock::FIELD => OptimisticLock::version($workOrder)])
            ->assertUnprocessable()
            ->assertJsonPath('message', self::NOT_EDITABLE);

        if ($status !== 'cancelled') {
            $this->deleteJson(route('admin.spk.destroy', $workOrder))
                ->assertUnprocessable()
                ->assertJsonPath('message', self::NOT_DELETABLE);
            $this->assertNotSoftDeleted($workOrder);
        }

        $this->assertSame('as rendered', $workOrder->fresh()->title);
    }

    public function test_two_saves_racing_on_one_spk_let_exactly_one_win(): void
    {
        $workOrder = $this->workOrder();
        $rendered = OptimisticLock::version($workOrder);
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $workOrder->company_id, 'work_order_id' => $workOrder->id, 'expected' => $rendered, 'title' => 'saved by A'],
                ['company_id' => $workOrder->company_id, 'work_order_id' => $workOrder->id, 'expected' => $rendered, 'title' => 'saved by B'],
            ], <<<'PHP'
                config(['logging.default' => 'null']);

                Modules\SPK\Services\SpkService::update(
                    Modules\SPK\Models\WorkOrder::query()->findOrFail($args['work_order_id']),
                    ['title' => $args['title']],
                    $args['expected'],
                );

                return ['saved' => $args['title']];
            PHP);

            $saved = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
            $refused = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));

            $this->assertCount(1, $saved, json_encode($results));
            $this->assertCount(1, $refused, json_encode($results));
            $this->assertSame(409, $refused[0]['status']);
            $this->assertSame(OptimisticLock::MESSAGE, $refused[0]['message']);
            $this->assertSame($saved[0]['result']['saved'], WorkOrder::withoutCompany()->findOrFail($workOrder->id)->title, 'the winner is what the row holds');
        } finally {
            $this->purgeCompany($workOrder->company_id);
        }
    }

    public function test_an_update_that_waited_for_assignment_is_refused(): void
    {
        $workOrder = $this->workOrder('generated');
        $rendered = OptimisticLock::version($workOrder);
        $this->commitFixture();

        try {
            $results = $this->raceAgainstAStatusChange($workOrder, 'assigned', <<<'PHP'
                Modules\SPK\Services\SpkService::update($stale, ['title' => 'late edit'], $args['expected']);

                return ['updated' => true];
            PHP, $rendered);

            $this->assertSame(['moved' => true], $results[0]['result']);
            $this->assertFalse($results[1]['ok'], json_encode($results));
            $this->assertSame(422, $results[1]['status']);
            $this->assertSame(self::NOT_EDITABLE, $results[1]['message']);

            $row = WorkOrder::withoutCompany()->findOrFail($workOrder->id);
            $this->assertSame('assigned', $row->status);
            $this->assertSame('as rendered', $row->title, 'no edit lands on an SPK that was already assigned');
        } finally {
            $this->purgeCompany($workOrder->company_id);
        }
    }

    public function test_a_delete_that_waited_for_generation_is_refused(): void
    {
        $workOrder = $this->workOrder('draft');
        $this->commitFixture();

        try {
            $results = $this->raceAgainstAStatusChange($workOrder, 'generated', <<<'PHP'
                Modules\SPK\Services\SpkService::destroy($stale);

                return ['deleted' => true];
            PHP);

            $this->assertSame(['moved' => true], $results[0]['result']);
            $this->assertFalse($results[1]['ok'], json_encode($results));
            $this->assertSame(422, $results[1]['status']);
            $this->assertSame(self::NOT_DELETABLE, $results[1]['message']);

            $row = WorkOrder::withoutCompany()->withTrashed()->findOrFail($workOrder->id);
            $this->assertSame('generated', $row->status);
            $this->assertNull($row->deleted_at, 'an SPK that was issued is never removed by a request that saw it as a draft');
        } finally {
            $this->purgeCompany($workOrder->company_id);
        }
    }

    /**
     * Worker 0 locks the SPK, moves it to $to and holds the lock for 1.2 s (the row change SpkService::generate and
     * assign make, without their actors). Worker 1 loads the SPK first, starts 400 ms later and runs $change with that
     * stale copy in $stale.
     *
     * @return list<array{ok: bool, status?: int, result?: mixed, message?: string}>
     */
    private function raceAgainstAStatusChange(WorkOrder $workOrder, string $to, string $change, ?string $expected = null): array
    {
        $payload = ['company_id' => $workOrder->company_id, 'work_order_id' => $workOrder->id, 'expected' => $expected, 'to' => $to];

        return $this->race([
            [...$payload, 'role' => 'mover'],
            [...$payload, 'role' => 'changer'],
        ], <<<PHP
            config(['logging.default' => 'null']);

            if (\$args['role'] === 'mover') {
                Illuminate\\Support\\Facades\\DB::transaction(function () use (\$args) {
                    Modules\\SPK\\Models\\WorkOrder::query()->whereKey(\$args['work_order_id'])->lockForUpdate()->firstOrFail()->update(['status' => \$args['to']]);
                    usleep(1200000);
                });

                return ['moved' => true];
            }

            \$stale = Modules\\SPK\\Models\\WorkOrder::query()->findOrFail(\$args['work_order_id']);
            usleep(400000);

            {$change}
            PHP);
    }
}
