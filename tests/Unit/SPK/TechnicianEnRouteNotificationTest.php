<?php

namespace Tests\Unit\SPK;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\SPK\Notifications\TechnicianEnRouteNotification;
use Tests\TestCase;

/**
 * One "Berangkat" is one notification: its id is made of the SPK and the moment the technician left, so the same trip can
 * never be stored twice.
 */
class TechnicianEnRouteNotificationTest extends TestCase
{
    private const LEFT_AT = '2026-10-16 01:30:00';

    public function test_the_same_trip_has_the_same_id_whatever_the_fraction_of_the_second(): void
    {
        $a = $this->notification(12, Carbon::parse(self::LEFT_AT));
        $b = $this->notification(12, Carbon::parse(self::LEFT_AT)->addMicroseconds(678901));

        $this->assertTrue(Str::isUuid($a->id));
        $this->assertSame($a->id, $b->id);
        $this->assertSame(TechnicianEnRouteNotification::idFor(12, Carbon::parse(self::LEFT_AT)), $a->id);
    }

    public function test_another_moment_or_another_spk_is_another_trip(): void
    {
        $trip = $this->notification(12, Carbon::parse(self::LEFT_AT));

        $this->assertNotSame($trip->id, $this->notification(12, Carbon::parse(self::LEFT_AT)->addSecond())->id);
        $this->assertNotSame($trip->id, $this->notification(13, Carbon::parse(self::LEFT_AT))->id);
    }

    public function test_the_company_and_the_name_of_the_technician_do_not_change_which_trip_it_is(): void
    {
        $this->assertSame(
            $this->notification(12, Carbon::parse(self::LEFT_AT), companyId: 1, firstName: 'Andi')->id,
            $this->notification(12, Carbon::parse(self::LEFT_AT), companyId: 2, firstName: 'Rina')->id,
        );
    }

    private function notification(int $workOrderId, Carbon $leftAt, int $companyId = 1, string $firstName = 'Andi'): TechnicianEnRouteNotification
    {
        return new TechnicianEnRouteNotification($companyId, $workOrderId, $firstName, $leftAt);
    }
}
