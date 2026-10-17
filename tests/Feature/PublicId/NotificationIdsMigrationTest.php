<?php

namespace Tests\Feature\PublicId;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Service\Models\Announcement;
use Modules\SPK\Models\WorkOrder;
use Tests\TestCase;

/** Spec .scratch/opaque-ids D6: the bell rows written before the public ids name their records by public id too. */
class NotificationIdsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_22_200000_rewrite_notification_ids_to_public_ids.php';

    private Company $company;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->recipient = User::factory()->create(['company_id' => $this->company->id]);
    }

    private function workOrder(): WorkOrder
    {
        $workOrder = new WorkOrder();
        $workOrder->forceFill([
            'company_id' => $this->company->id,
            'code' => 'SPK-'.Str::upper(Str::random(6)),
            'type' => 'maintenance',
            'title' => 'Uji pemindahan id',
            'status' => 'draft',
            'source' => 'manual',
            'priority' => 'low',
            'created_by' => $this->recipient->id,
        ])->save();

        return $workOrder;
    }

    public function test_up_rewrites_every_integer_reference_to_the_public_id_of_its_record(): void
    {
        $invoice = Invoice::factory()->create(['company_id' => $this->company->id]);
        $workOrder = $this->workOrder();
        $announcement = Announcement::factory()->create(['company_id' => $this->company->id]);

        $invoiceRow = $this->push(['type' => 'invoice_issued', 'invoice_id' => $invoice->id]);
        $workOrderRow = $this->push(['type' => 'spk_approved', 'work_order_id' => (string) $workOrder->id, 'company_id' => $this->company->id, 'work_order_code' => 'SPK-1']);
        $announcementRow = $this->push([
            'type' => 'announcement_published',
            'announcement_id' => $announcement->id,
            'subject' => ['type' => 'announcement', 'id' => $announcement->id],
            'title' => 'Libur',
        ]);
        $userRow = $this->push(['type' => 'access_link_notice', 'user_id' => $this->recipient->id]);

        $this->migration()->up();

        $this->assertEquals(['type' => 'invoice_issued', 'invoice_id' => $invoice->public_id], $this->data($invoiceRow));
        $this->assertEquals(
            ['type' => 'spk_approved', 'work_order_id' => $workOrder->public_id, 'company_id' => $this->company->id, 'work_order_code' => 'SPK-1'],
            $this->data($workOrderRow),
            'only the references move: the company id the bell checks against stays',
        );
        $this->assertEquals(
            ['type' => 'announcement_published', 'announcement_id' => $announcement->public_id, 'subject' => ['type' => 'announcement', 'id' => $announcement->public_id], 'title' => 'Libur'],
            $this->data($announcementRow),
        );
        $this->assertEquals(['type' => 'access_link_notice', 'user_id' => $this->recipient->public_id], $this->data($userRow));
    }

    public function test_a_record_that_no_longer_exists_leaves_null_and_other_values_are_untouched(): void
    {
        $gone = $this->push(['type' => 'ticket_assigned', 'ticket_id' => 987654, 'ticket_code' => 'TKT-9']);
        $schedule = $this->push(['type' => 'billing_schedule_failed', 'job_id' => 5]);
        $already = $this->push(['type' => 'invoice_issued', 'invoice_id' => strtolower((string) Str::ulid())]);
        $alreadyData = $this->data($already);

        $this->migration()->up();

        $this->assertEquals(['type' => 'ticket_assigned', 'ticket_id' => null, 'ticket_code' => 'TKT-9'], $this->data($gone));
        $this->assertEquals(['type' => 'billing_schedule_failed', 'job_id' => 5], $this->data($schedule));
        $this->assertEquals($alreadyData, $this->data($already));
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $invoice = Invoice::factory()->create(['company_id' => $this->company->id]);
        $row = $this->push(['type' => 'invoice_issued', 'invoice_id' => $invoice->id]);

        $this->migration()->up();
        $once = $this->data($row);
        $this->migration()->up();

        $this->assertEquals($once, $this->data($row));
    }

    public function test_down_puts_the_integer_ids_back(): void
    {
        $invoice = Invoice::factory()->create(['company_id' => $this->company->id]);
        $workOrder = $this->workOrder();
        $invoiceRow = $this->push(['type' => 'invoice_issued', 'invoice_id' => $invoice->id]);
        $subjectRow = $this->push(['type' => 'spk_approved', 'work_order_id' => $workOrder->id, 'subject' => ['type' => 'work_order', 'id' => $workOrder->id]]);

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertEquals(['type' => 'invoice_issued', 'invoice_id' => $invoice->id], $this->data($invoiceRow));
        $this->assertEquals(
            ['type' => 'spk_approved', 'work_order_id' => $workOrder->id, 'subject' => ['type' => 'work_order', 'id' => $workOrder->id]],
            $this->data($subjectRow),
        );
    }

    /** @param  array<string, mixed>  $data */
    private function push(array $data): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\Probe',
            'notifiable_type' => User::class,
            'notifiable_id' => $this->recipient->id,
            'data' => json_encode($data),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function data(string $id): array
    {
        return json_decode((string) DB::table('notifications')->where('id', $id)->value('data'), true);
    }

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }
}
