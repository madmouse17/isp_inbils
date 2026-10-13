<?php

namespace Tests\Feature\Admin;

use App\Http\Resources\ApprovalInboxItemResource;
use App\Http\Resources\DispatchItemResource;
use App\Http\Resources\MyWorkItemResource;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\InventoryRequest;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Carbon 3 diffs are signed: `now()->diffInSeconds($past)` is negative, so a clamp to zero hid every age.
 */
class WorkItemAgeSecondsTest extends TestCase
{
    private const THREE_DAYS = 259200;

    /** @return array<string, array{class-string<JsonResource>, class-string<Model>}> */
    public static function rows(): array
    {
        return [
            'dispatch ticket' => [DispatchItemResource::class, Ticket::class],
            'dispatch spk' => [DispatchItemResource::class, WorkOrder::class],
            'my work ticket' => [MyWorkItemResource::class, Ticket::class],
            'my work spk' => [MyWorkItemResource::class, WorkOrder::class],
            'approval spk' => [ApprovalInboxItemResource::class, WorkOrder::class],
            'approval inventory request' => [ApprovalInboxItemResource::class, InventoryRequest::class],
        ];
    }

    /**
     * @param  class-string<JsonResource>  $resource
     * @param  class-string<Model>  $model
     */
    #[DataProvider('rows')]
    public function test_three_day_old_item_reports_259200_seconds(string $resource, string $model): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');

        $item = new $model();
        $item->created_at = Carbon::now()->subDays(3);

        $payload = (new $resource($item))->toArray(Request::create('/'));

        $this->assertSame(self::THREE_DAYS, $payload['age_seconds']);

        Carbon::setTestNow();
    }

    public function test_item_created_in_the_future_reports_zero(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');

        $ticket = new Ticket();
        $ticket->created_at = Carbon::now()->addMinute();

        $payload = (new DispatchItemResource($ticket))->toArray(Request::create('/'));

        $this->assertSame(0, $payload['age_seconds']);

        Carbon::setTestNow();
    }
}
