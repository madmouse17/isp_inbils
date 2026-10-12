<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Notifications\TicketAssignedNotification;
use Modules\Ticketing\Services\TicketService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class NotificationCenterTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    public function test_notification_center_is_owned_by_the_actor_and_isolated_by_company(): void
    {
        $actor = $this->createCompanyUser();
        $sameCompanyUser = User::factory()->create([
            'company_id' => $actor->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $otherCompany = Company::factory()->create(['is_active' => true]);
        $otherCompanyUser = User::factory()->create([
            'company_id' => $otherCompany->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $actorNotification = $this->notification($actor, ['type' => 'ticket_assigned', 'ticket_code' => 'TKT-A']);
        $sameCompanyNotification = $this->notification($sameCompanyUser, ['type' => 'ticket_assigned', 'ticket_code' => 'TKT-SAME']);
        $otherCompanyNotification = $this->notification($otherCompanyUser, ['type' => 'ticket_assigned', 'ticket_code' => 'TKT-OTHER']);

        $this->actingAs($actor)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Notifications/Index')
                ->has('notifications.data', 1)
                ->where('notifications.unreadCount', 1)
                ->where('notifications.data.0.id', (string) $actorNotification->id)
            );

        $foreignResponse = $this->actingAs($actor)
            ->patch(route('admin.notifications.read', $sameCompanyNotification->id));
        $missingResponse = $this->actingAs($actor)
            ->patch(route('admin.notifications.read', (string) Str::uuid()));

        $this->assertSame(404, $foreignResponse->status());
        $this->assertSame(404, $missingResponse->status());
        $this->assertNull($sameCompanyNotification->fresh()->read_at);
        $this->assertNull($otherCompanyNotification->fresh()->read_at);
    }

    public function test_unread_count_includes_older_pages_when_the_current_page_is_read(): void
    {
        $actor = $this->createCompanyUser();
        $oldest = null;

        for ($index = 1; $index <= 21; $index++) {
            $notification = $this->notification(
                $actor,
                ['type' => 'billing_schedule_failed'],
                now()->subMinutes(21 - $index),
                $index === 1 ? null : now()->subMinute()
            );

            if ($index === 1) {
                $oldest = $notification;
            }
        }

        $this->actingAs($actor)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Notifications/Index')
                ->has('notifications.data', 20)
                ->where('notifications.data', fn (Collection $notifications): bool => $notifications->every(fn (array $notification): bool => $notification['read_at'] !== null))
                ->where('notifications.unreadCount', 1)
                ->where('notifications.meta.current_page', 1)
                ->where('notifications.meta.per_page', 20)
                ->where('notifications.meta.last_page', 2)
            );

        $this->actingAs($actor)
            ->get(route('admin.notifications.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('notifications.data', 1)
                ->where('notifications.data.0.id', (string) $oldest->id)
                ->where('notifications.data.0.read_at', null)
                ->where('notifications.unreadCount', 1)
                ->where('notifications.meta.current_page', 2)
            );
    }

    public function test_marking_one_and_all_notifications_read_is_idempotent(): void
    {
        $actor = $this->createCompanyUser();
        $otherUser = User::factory()->create(['company_id' => $actor->company_id]);
        $otherNotification = $this->notification($otherUser, ['type' => 'billing_schedule_failed']);
        $one = $this->notification($actor, ['type' => 'ticket_assigned', 'ticket_code' => 'TKT-ONE']);
        $two = $this->notification($actor, ['type' => 'ticket_sla_breached', 'ticket_code' => 'TKT-TWO']);
        $alreadyRead = $this->notification($actor, ['type' => 'billing_schedule_failed'], readAt: now()->subMinute());

        $this->actingAs($actor)
            ->from(route('admin.notifications.index'))
            ->patch(route('admin.notifications.read', $one->id))
            ->assertRedirect(route('admin.notifications.index'));
        $firstReadAt = $one->fresh()->read_at;

        $this->travel(1)->minutes();
        $this->actingAs($actor)
            ->from(route('admin.notifications.index'))
            ->patch(route('admin.notifications.read', $one->id))
            ->assertRedirect(route('admin.notifications.index'));

        $this->assertNotNull($firstReadAt);
        $this->assertTrue($one->fresh()->read_at->equalTo($firstReadAt));

        $this->travel(1)->minutes();

        $this->actingAs($actor)
            ->from(route('admin.notifications.index'))
            ->patch(route('admin.notifications.read-all'))
            ->assertRedirect(route('admin.notifications.index'));
        $readAllAt = $two->fresh()->read_at;

        $this->travel(1)->minutes();

        $this->actingAs($actor)
            ->from(route('admin.notifications.index'))
            ->patch(route('admin.notifications.read-all'))
            ->assertRedirect(route('admin.notifications.index'));

        $this->assertNotNull($readAllAt);
        $this->assertTrue($two->fresh()->read_at->equalTo($readAllAt));
        $this->assertTrue($alreadyRead->fresh()->read_at->equalTo($alreadyRead->read_at));
        $this->assertTrue($one->fresh()->read_at->equalTo($firstReadAt));
        $this->assertNull($otherNotification->fresh()->read_at);
        $this->assertSame(0, $actor->fresh()->unreadNotifications()->count());

        $this->actingAs($actor)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.unreadCount', 0)
            );
    }

    public function test_unknown_notification_payload_is_rendered_safely(): void
    {
        $actor = $this->createCompanyUser();
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'unknown-notification-class',
            'notifiable_type' => User::class,
            'notifiable_id' => $actor->id,
            'data' => json_encode('malformed payload', JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($actor)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Notifications/Index')
                ->has('notifications.data', 1)
                ->where('notifications.data.0.id', $id)
                ->where('notifications.data.0.type', 'unknown')
                ->where('notifications.data.0.detail_url', null)
            );
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function inventoryNotificationTypes(): array
    {
        return [
            'request submitted' => ['inventory_request_submitted', 'Permintaan barang diajukan', 'Permintaan REQ-9 menunggu tinjauan gudang.', 'Sebuah permintaan barang menunggu tinjauan gudang.'],
            'request approved' => ['inventory_request_approved', 'Permintaan barang disetujui', 'Permintaan REQ-9 disetujui dan stoknya sudah dicadangkan.', 'Sebuah permintaan barang disetujui dan stoknya sudah dicadangkan.'],
            'request ready' => ['inventory_request_ready', 'Permintaan barang siap diambil', 'Permintaan REQ-9 siap diambil.', 'Sebuah permintaan barang siap diambil.'],
            'request rejected' => ['inventory_request_rejected', 'Permintaan barang ditolak', 'Permintaan REQ-9 ditolak.', 'Sebuah permintaan barang ditolak.'],
            'return declared' => ['material_return_declared', 'Pengembalian material dideklarasikan', 'Kasus pengembalian #12 sudah dideklarasikan dan menunggu penerimaan gudang.', 'Pengembalian material sudah dideklarasikan dan menunggu penerimaan gudang.'],
            'return exception' => ['material_return_exception', 'Pengembalian material bermasalah', 'Kasus pengembalian #12 memiliki selisih yang perlu diselesaikan.', 'Pengembalian material memiliki selisih yang perlu diselesaikan.'],
            'return rejected' => ['material_return_rejected', 'Pengembalian material ditolak', 'Serah terima kasus pengembalian #12 ditolak. Periksa kembali deklarasinya.', 'Serah terima pengembalian material ditolak. Periksa kembali deklarasinya.'],
        ];
    }

    #[DataProvider('inventoryNotificationTypes')]
    public function test_inventory_and_material_return_notifications_have_titles_and_messages_but_no_link(
        string $type,
        string $title,
        string $message,
        string $fallbackMessage,
    ): void {
        $actor = $this->createCompanyUser();
        $withReference = $this->notification($actor, [
            'type' => $type,
            'company_id' => $actor->company_id,
            'request_id' => 9,
            'request_code' => 'REQ-9',
            'case_id' => 12,
        ], now()->subMinute());
        $withoutReference = $this->notification($actor, ['type' => $type, 'company_id' => $actor->company_id]);

        $this->actingAs($actor)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.data', function (Collection $notifications) use ($type, $title, $message, $fallbackMessage, $withReference, $withoutReference): bool {
                    $byId = $notifications->keyBy('id');

                    foreach ([[$withReference, $message], [$withoutReference, $fallbackMessage]] as [$notification, $expectedMessage]) {
                        $row = $byId->get((string) $notification->id);

                        if ($row['type'] !== $type || $row['title'] !== $title || $row['message'] !== $expectedMessage || $row['detail_url'] !== null) {
                            return false;
                        }
                    }

                    return true;
                })
            );
    }

    public function test_ticket_deep_links_require_same_company_and_ticket_view_policy(): void
    {
        $actor = $this->createCompanyUser();
        $authorizedTicket = $this->ticket($actor, 'authorized');
        $otherCompany = Company::factory()->create(['is_active' => true]);
        $otherCompanyUser = User::factory()->create([
            'company_id' => $otherCompany->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $otherTicket = $this->ticket($otherCompanyUser, 'other-company');

        $authorized = $this->notification($actor, [
            'type' => 'ticket_assigned',
            'company_id' => $actor->company_id,
            'ticket_id' => $authorizedTicket->id,
            'ticket_code' => $authorizedTicket->code,
        ]);
        $nullCompany = $this->notification($actor, [
            'type' => 'ticket_sla_breached',
            'company_id' => null,
            'ticket_id' => $authorizedTicket->id,
            'ticket_code' => $authorizedTicket->code,
        ]);
        $malformedCompany = $this->notification($actor, [
            'type' => 'ticket_assigned',
            'company_id' => 'not-a-company-id',
            'ticket_id' => $authorizedTicket->id,
            'ticket_code' => $authorizedTicket->code,
        ]);
        $foreignCompany = $this->notification($actor, [
            'type' => 'ticket_assigned',
            'company_id' => $otherCompany->id,
            'ticket_id' => $authorizedTicket->id,
            'ticket_code' => $authorizedTicket->code,
        ]);
        $crossCompany = $this->notification($actor, [
            'type' => 'ticket_assigned',
            'company_id' => $actor->company_id,
            'ticket_id' => $otherTicket->id,
            'ticket_code' => $otherTicket->code,
        ]);
        $stale = $this->notification($actor, [
            'type' => 'ticket_sla_breached',
            'company_id' => $actor->company_id,
            'ticket_id' => 999999999,
            'ticket_code' => 'TKT-STALE',
        ]);

        $this->actingAs($actor)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.data', function (Collection $notifications) use ($stale, $crossCompany, $nullCompany, $malformedCompany, $foreignCompany, $authorized, $authorizedTicket): bool {
                    $byId = $notifications->keyBy('id');

                    return data_get($byId->get((string) $stale->id), 'detail_url') === null
                        && data_get($byId->get((string) $crossCompany->id), 'detail_url') === null
                        && data_get($byId->get((string) $nullCompany->id), 'detail_url') === null
                        && data_get($byId->get((string) $malformedCompany->id), 'detail_url') === null
                        && data_get($byId->get((string) $foreignCompany->id), 'detail_url') === null
                        && data_get($byId->get((string) $authorized->id), 'detail_url') === route('admin.tickets.show', $authorizedTicket);
                })
            );

        $deniedUser = User::factory()->create([
            'company_id' => $actor->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $denied = $this->notification($deniedUser, [
            'type' => 'ticket_assigned',
            'company_id' => $actor->company_id,
            'ticket_id' => $authorizedTicket->id,
            'ticket_code' => $authorizedTicket->code,
        ]);

        $this->actingAs($deniedUser)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('notifications.data', 1)
                ->where('notifications.data', function (Collection $notifications) use ($denied): bool {
                    return data_get($notifications->keyBy('id')->get((string) $denied->id), 'detail_url') === null;
                })
            );
    }

    public function test_ticket_assignment_produces_mail_and_database_notification(): void
    {
        $actor = $this->createCompanyUser();
        $this->actingAs($actor);

        $handler = User::factory()->create([
            'company_id' => $actor->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $handler->givePermissionTo('ticket.start');
        $ticket = $this->ticket($actor, 'producer');
        $channels = [];

        $this->app['events']->listen(NotificationSent::class, function (NotificationSent $event) use (&$channels, $handler): void {
            if ($event->notifiable->is($handler) && $event->notification instanceof TicketAssignedNotification) {
                $channels[] = $event->channel;
            }
        });

        TicketService::assign(
            $ticket,
            $handler->id,
            $actor->id,
            null,
            $ticket->fresh()->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z')
        );

        $databaseNotification = $handler->notifications()->first();
        $this->assertNotNull($databaseNotification);
        $this->assertSame(TicketAssignedNotification::class, $databaseNotification->type);
        $this->assertSame('ticket_assigned', $databaseNotification->data['type']);
        $this->assertSame($ticket->id, $databaseNotification->data['ticket_id']);
        $this->assertEqualsCanonicalizing(['mail', 'database'], $channels);
    }

    /** @param array<string, mixed> $data */
    private function notification(User $user, array $data, ?Carbon $createdAt = null, ?Carbon $readAt = null): DatabaseNotification
    {
        /** @var DatabaseNotification $notification */
        $notification = $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test-notification',
            'data' => $data,
            'read_at' => $readAt,
        ]);

        if ($createdAt !== null) {
            $notification->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();
        }

        return $notification->fresh();
    }

    private function ticket(User $creator, string $suffix): Ticket
    {
        $code = 'NT-'.$suffix.'-'.substr((string) Str::uuid(), 0, 8);
        $category = TicketCategory::query()->create([
            'company_id' => $creator->company_id,
            'name' => 'Notification tests',
            'code' => 'NTC-'.$suffix.'-'.substr((string) Str::uuid(), 0, 8),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        return Ticket::query()->create([
            'company_id' => $creator->company_id,
            'category_id' => $category->id,
            'code' => $code,
            'title' => 'Notification ticket',
            'description' => 'Ticket for notification feature coverage.',
            'source' => 'customer',
            'status' => 'open',
            'priority' => 'medium',
            'created_by' => $creator->id,
            'sla_deadline' => now()->addDay(),
        ]);
    }
}
