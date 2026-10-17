<?php

namespace Tests\Unit\SPK;

use Illuminate\Database\Eloquent\Collection;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderAssignment;
use Modules\SPK\Support\TripSteps;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The four steps every surface shows (spec F2): which one a visit is at, and the time of each step it reached.
 */
class TripStepsTest extends TestCase
{
    private const KEYS = ['assigned', 'en_route', 'in_progress', 'installed'];

    private const ASSIGNED = '2026-10-16 01:00:00';

    private const EN_ROUTE = '2026-10-16 01:30:00';

    private const STARTED = '2026-10-16 02:00:00';

    private const SUBMITTED = '2026-10-16 04:00:00';

    /**
     * @return array<string, array{0: array<string, mixed>, 1: ?string, 2: list<?string>}> columns of the SPK, the step it is at,
     *                                                                                     and the time shown on each of the four steps
     */
    public static function visits(): array
    {
        return [
            'a draft has not reached a step' => [['status' => 'draft'], null, [null, null, null, null]],
            'generated waits for a technician' => [['status' => 'generated'], null, [null, null, null, null]],
            'assigned, not yet on the way' => [['status' => 'assigned'], 'assigned', [self::ASSIGNED, null, null, null]],
            'assigned and on the way' => [['status' => 'assigned', 'en_route_at' => self::EN_ROUTE], 'en_route', [self::ASSIGNED, self::EN_ROUTE, null, null]],
            'started without ever leaving through the app' => [['status' => 'in_progress', 'started_at' => self::STARTED], 'in_progress', [self::ASSIGNED, null, self::STARTED, null]],
            'started after leaving' => [['status' => 'in_progress', 'en_route_at' => self::EN_ROUTE, 'started_at' => self::STARTED], 'in_progress', [self::ASSIGNED, self::EN_ROUTE, self::STARTED, null]],
            'waiting for review is installed' => [['status' => 'waiting_review', 'en_route_at' => self::EN_ROUTE, 'started_at' => self::STARTED, 'submitted_at' => self::SUBMITTED], 'installed', [self::ASSIGNED, self::EN_ROUTE, self::STARTED, self::SUBMITTED]],
            'completed is installed' => [['status' => 'completed', 'en_route_at' => self::EN_ROUTE, 'started_at' => self::STARTED, 'submitted_at' => self::SUBMITTED], 'installed', [self::ASSIGNED, self::EN_ROUTE, self::STARTED, self::SUBMITTED]],
            'rejected goes back to working and shows no finish time' => [['status' => 'rejected', 'en_route_at' => self::EN_ROUTE, 'started_at' => self::STARTED, 'submitted_at' => self::SUBMITTED], 'in_progress', [self::ASSIGNED, self::EN_ROUTE, self::STARTED, null]],
            'an assigned SPK shows no time of an earlier run even if its columns still hold them (rows from before assign cleared them)' => [['status' => 'assigned', 'started_at' => self::STARTED, 'submitted_at' => self::SUBMITTED], 'assigned', [self::ASSIGNED, null, null, null]],
        ];
    }

    /**
     * @param  array<string, mixed>  $columns
     * @param  list<?string>  $times
     */
    #[DataProvider('visits')]
    public function test_a_visit_is_at_one_step_and_only_the_steps_it_reached_carry_a_time(array $columns, ?string $current, array $times): void
    {
        $workOrder = $this->workOrder($columns, [new WorkOrderAssignment(['assigned_at' => self::ASSIGNED, 'unassigned_at' => null])]);

        $steps = TripSteps::of($workOrder);

        $this->assertSame($current, TripSteps::current($workOrder));
        $this->assertSame(self::KEYS, array_column($steps, 'key'));
        $this->assertSame(['Ditugaskan', 'Dalam perjalanan', 'Sedang dikerjakan', 'Selesai dipasang'], array_column($steps, 'label'));
        $this->assertSame($times, array_map(fn (array $step): ?string => $step['at']?->format('Y-m-d H:i:s'), $steps));
    }

    public function test_a_cancelled_visit_has_no_steps(): void
    {
        $workOrder = $this->workOrder(['status' => 'cancelled', 'started_at' => self::STARTED], [new WorkOrderAssignment(['assigned_at' => self::ASSIGNED])]);

        $this->assertSame([], TripSteps::of($workOrder));
        $this->assertNull(TripSteps::current($workOrder));
    }

    public function test_the_assigned_time_is_the_active_assignment_not_an_earlier_technicians(): void
    {
        $workOrder = $this->workOrder(['status' => 'assigned'], [
            new WorkOrderAssignment(['assigned_at' => '2026-10-15 08:00:00', 'unassigned_at' => '2026-10-16 00:30:00']),
            new WorkOrderAssignment(['assigned_at' => self::ASSIGNED, 'unassigned_at' => null]),
        ]);

        $this->assertSame(self::ASSIGNED, TripSteps::of($workOrder)[0]['at']->format('Y-m-d H:i:s'));
    }

    public function test_the_assigned_step_has_no_time_without_an_active_assignment(): void
    {
        $workOrder = $this->workOrder(['status' => 'assigned'], [
            new WorkOrderAssignment(['assigned_at' => '2026-10-15 08:00:00', 'unassigned_at' => '2026-10-16 00:30:00']),
        ]);

        $this->assertNull(TripSteps::of($workOrder)[0]['at']);
        $this->assertSame('assigned', TripSteps::current($workOrder));
    }

    /**
     * @param  array<string, mixed>  $columns
     * @param  list<WorkOrderAssignment>  $assignments
     */
    private function workOrder(array $columns, array $assignments): WorkOrder
    {
        $workOrder = (new WorkOrder())->forceFill($columns);
        $workOrder->setRelation('assignments', new Collection($assignments));

        return $workOrder;
    }
}
