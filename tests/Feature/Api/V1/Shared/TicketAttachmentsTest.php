<?php

namespace Tests\Feature\Api\V1\Shared;

use App\Http\Middleware\Api\EnforceIdempotency;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Api\V1\PostsMultipart;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * Files on a ticket from the two apps: the customer on their own ticket, the technician on one assigned to them.
 * Same permission as the web (ticket.attachment.upload), same ticket policy, and the uploader alone may withdraw
 * a file, while the ticket is still being worked.
 */
class TicketAttachmentsTest extends TestCase
{
    use InteractsWithMobileApi;
    use PostsMultipart;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_customer_attaches_a_photo_to_their_own_ticket(): void
    {
        [$user, $customer] = $this->customerLogin();
        $ticket = $this->ticket($user->company_id, ['customer_id' => $customer->id]);
        $token = $this->issueToken($user, 'customer');

        $response = $this->multipart("customer/tickets/{$ticket->public_id}/attachments", [
            'file' => UploadedFile::fake()->image('modem.jpg')->size(3072),
            'caption' => 'Lampu LOS merah',
        ], $token)->assertCreated()
            ->assertJsonPath('data.file_name', 'modem.jpg')
            ->assertJsonPath('data.mime_type', 'image/jpeg')
            ->assertJsonPath('data.caption', 'Lampu LOS merah')
            ->assertJsonPath('data.mine', true)
            ->assertJsonPath('data.can_delete', true);
        $this->assertIsInt($response->json('data.size'));
        $this->assertSame(['data'], array_keys($response->json()));

        $media = $ticket->fresh()->getMedia('attachments')->sole();
        $this->assertSame($media->public_id, $response->json('data.id'));
        $this->assertSame($user->id, $media->getCustomProperty('uploaded_by'));
        $this->assertSame($user->company_id, $media->getCustomProperty('company_id'));
        Storage::disk('local')->assertExists($media->getPathRelativeToRoot());

        $this->api('GET', "customer/tickets/{$ticket->public_id}", token: $token)
            ->assertOk()
            ->assertJsonPath('data.attachments.0.id', $media->public_id)
            ->assertJsonPath('data.attachments.0.mine', true)
            ->assertJsonPath('data.attachments.0.can_delete', true)
            ->assertJsonPath('data.attachments.0.caption', 'Lampu LOS merah');
        $this->api('GET', "media/{$media->public_id}", token: $token)->assertOk();

        $this->assertTrue(Activity::query()->where('log_name', 'ticket')->where('description', 'attachment_uploaded')->exists());
    }

    public function test_a_pdf_without_caption_is_accepted_and_the_caption_is_null(): void
    {
        [$user, $customer] = $this->customerLogin();
        $ticket = $this->ticket($user->company_id, ['customer_id' => $customer->id]);

        $this->multipart("customer/tickets/{$ticket->public_id}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('bill.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n"),
        ], $this->issueToken($user, 'customer'))->assertCreated()
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.caption', null);
    }

    public function test_an_assigned_technician_attaches_a_photo_and_reads_it_back_in_the_ticket(): void
    {
        $technician = $this->technicianLogin();
        $ticket = $this->ticket($technician->company_id, ['status' => 'on_progress', 'assigned_to' => $technician->id]);
        $token = $this->issueToken($technician, 'technician');

        $this->multipart("technician/tickets/{$ticket->public_id}/attachments", [
            'file' => UploadedFile::fake()->image('odp.png'),
            'caption' => 'ODP tertutup',
        ], $token)->assertCreated()
            ->assertJsonPath('data.file_name', 'odp.png')
            ->assertJsonPath('data.mine', true)
            ->assertJsonPath('data.can_delete', true);

        $this->api('GET', "technician/tickets/{$ticket->public_id}", token: $token)
            ->assertOk()
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.attachments.0.caption', 'ODP tertutup')
            ->assertJsonPath('data.attachments.0.mine', true)
            ->assertJsonPath('data.attachments.0.can_delete', true);
        $this->assertSame($technician->id, $ticket->fresh()->getMedia('attachments')->sole()->getCustomProperty('uploaded_by'));
    }

    public function test_uploads_that_are_not_a_small_photo_or_pdf_are_422_on_the_file_field(): void
    {
        [$user, $customer] = $this->customerLogin();
        $ticket = $this->ticket($user->company_id, ['customer_id' => $customer->id]);
        $token = $this->issueToken($user, 'customer');
        $jpegBytes = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

        foreach ([
            'too_large' => UploadedFile::fake()->image('large.jpg')->size(11264),
            'svg' => UploadedFile::fake()->createWithContent('bad.svg', '<svg><script>alert(1)</script></svg>'),
            'html' => UploadedFile::fake()->createWithContent('bad.html', '<html></html>'),
            'renamed_exe' => UploadedFile::fake()->createWithContent('proof.exe', $jpegBytes),
            'word' => UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            'text' => UploadedFile::fake()->createWithContent('notes.txt', 'plain text'),
        ] as $case => $file) {
            $this->multipart("customer/tickets/{$ticket->public_id}/attachments", ['file' => $file], $token)
                ->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonValidationErrors(['file'], 'errors');
            $this->assertSame(0, $ticket->fresh()->getMedia('attachments')->count(), $case);
        }

        $this->multipart("customer/tickets/{$ticket->public_id}/attachments", [], $token)->assertStatus(422)->assertJsonValidationErrors(['file'], 'errors');
        $this->multipart("customer/tickets/{$ticket->public_id}/attachments", [
            'file' => UploadedFile::fake()->image('ok.jpg'),
            'caption' => str_repeat('a', 256),
        ], $token)->assertStatus(422)->assertJsonValidationErrors(['caption'], 'errors');
        $this->assertSame(0, $ticket->fresh()->getMedia('attachments')->count());
    }

    public function test_a_customer_cannot_reach_another_customers_ticket_or_another_companys(): void
    {
        [$user, $customer] = $this->customerLogin();
        [, $peer] = $this->customerLogin($user->company);
        [$foreignUser, $foreignCustomer] = $this->customerLogin();
        $own = $this->ticket($user->company_id, ['customer_id' => $customer->id]);
        $peers = $this->ticket($user->company_id, ['customer_id' => $peer->id]);
        $foreign = $this->ticket($foreignUser->company_id, ['customer_id' => $foreignCustomer->id]);
        $token = $this->issueToken($user, 'customer');
        $file = fn (): UploadedFile => UploadedFile::fake()->image('x.jpg');

        $this->multipart("customer/tickets/{$peers->public_id}/attachments", ['file' => $file()], $token)->assertNotFound();
        $this->multipart("customer/tickets/{$foreign->public_id}/attachments", ['file' => $file()], $token)->assertNotFound();
        $this->multipart('customer/tickets/9999999/attachments', ['file' => $file()], $token)->assertNotFound();
        // The lookup comes before the file is looked at: a stranger's id is a 404 even with nothing attached.
        $this->multipart("customer/tickets/{$peers->public_id}/attachments", [], $token)->assertNotFound();
        $this->api('DELETE', "customer/tickets/{$peers->public_id}/attachments/1", token: $token)->assertNotFound();
        $this->api('DELETE', "customer/tickets/{$foreign->public_id}/attachments/1", token: $token)->assertNotFound();

        $this->assertSame(0, Media::query()->where('collection_name', 'attachments')->count());
        $this->assertSame(0, $own->fresh()->getMedia('attachments')->count());
    }

    public function test_a_technician_who_is_not_the_assignee_and_another_company_both_get_404(): void
    {
        $technician = $this->technicianLogin();
        $colleague = $this->technicianLogin($technician->company);
        $stranger = $this->technicianLogin();
        $ticket = $this->ticket($technician->company_id, ['status' => 'assigned', 'assigned_to' => $technician->id]);
        $file = fn (): UploadedFile => UploadedFile::fake()->image('x.jpg');

        // A ticket that is not theirs is a record the technician cannot see: the same 404 as another company's.
        $this->multipart("technician/tickets/{$ticket->public_id}/attachments", ['file' => $file()], $this->issueToken($colleague, 'technician'))
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
        $this->multipart("technician/tickets/{$ticket->public_id}/attachments", [], $this->issueToken($colleague, 'technician'))->assertNotFound();
        $this->multipart("technician/tickets/{$ticket->public_id}/attachments", ['file' => $file()], $this->issueToken($stranger, 'technician'))->assertNotFound();

        $this->assertSame(0, $ticket->fresh()->getMedia('attachments')->count());
    }

    public function test_the_permission_to_attach_is_needed_by_both_apps(): void
    {
        [$user, $customer] = $this->customerLogin();
        $technician = $this->technicianLogin($user->company);
        $customerTicket = $this->ticket($user->company_id, ['customer_id' => $customer->id]);
        $technicianTicket = $this->ticket($user->company_id, ['status' => 'assigned', 'assigned_to' => $technician->id]);
        $own = $customerTicket->addMediaFromString('png')->usingFileName('mine.png')
            ->withCustomProperties(['company_id' => $user->company_id, 'uploaded_by' => $user->id])->toMediaCollection('attachments');
        $this->revokeFromRole($user->company_id, 'customer', 'ticket.attachment.upload');
        $this->revokeFromRole($user->company_id, 'technician', 'ticket.attachment.upload');
        $file = fn (): UploadedFile => UploadedFile::fake()->image('x.jpg');

        $this->multipart("customer/tickets/{$customerTicket->public_id}/attachments", ['file' => $file()], $this->issueToken($user, 'customer'))
            ->assertForbidden();
        $this->multipart("technician/tickets/{$technicianTicket->public_id}/attachments", ['file' => $file()], $this->issueToken($technician, 'technician'))
            ->assertForbidden();
        $this->api('DELETE', "customer/tickets/{$customerTicket->public_id}/attachments/{$own->public_id}", token: $this->issueToken($user, 'customer'))
            ->assertForbidden();

        $this->assertSame(1, Media::query()->where('collection_name', 'attachments')->count());
        $this->assertSame(false, $this->api('GET', "customer/tickets/{$customerTicket->public_id}", token: $this->issueToken($user, 'customer'))->json('data.attachments.0.can_delete'));
    }

    public function test_the_uploader_removes_their_own_file_and_nobody_elses(): void
    {
        [$user, $customer] = $this->customerLogin();
        $technician = $this->technicianLogin($user->company);
        $staff = User::factory()->create(['company_id' => $user->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $ticket = $this->ticket($user->company_id, ['customer_id' => $customer->id, 'status' => 'assigned', 'assigned_to' => $technician->id]);
        $other = $this->ticket($user->company_id, ['customer_id' => $customer->id]);
        $add = fn (Ticket $on, ?User $by, string $name): Media => $on->addMediaFromString('png')->usingFileName($name)
            ->withCustomProperties(['company_id' => $on->company_id, 'uploaded_by' => $by?->id])->toMediaCollection('attachments');
        $mine = $add($ticket, $user, 'mine.png');
        $theirs = $add($ticket, $technician, 'tech.png');
        $staffs = $add($ticket, $staff, 'staff.png');
        $legacy = $add($ticket, null, 'legacy.png');
        $elsewhere = $add($other, $user, 'other-ticket.png');
        $customerToken = $this->issueToken($user, 'customer');
        $technicianToken = $this->issueToken($technician, 'technician');

        $flags = collect($this->api('GET', "customer/tickets/{$ticket->public_id}", token: $customerToken)->json('data.attachments'))
            ->mapWithKeys(fn (array $file): array => [$file['file_name'] => [$file['mine'], $file['can_delete']]])->all();
        $this->assertSame(['mine.png' => [true, true], 'tech.png' => [false, false], 'staff.png' => [false, false], 'legacy.png' => [false, false]], $flags);

        foreach ([$theirs, $staffs, $legacy] as $notMine) {
            $this->api('DELETE', "customer/tickets/{$ticket->public_id}/attachments/{$notMine->public_id}", token: $customerToken)->assertForbidden();
        }
        $this->api('DELETE', "technician/tickets/{$ticket->public_id}/attachments/{$mine->public_id}", token: $technicianToken)->assertForbidden();
        // A file of another ticket is not on this one, whoever uploaded it.
        $this->api('DELETE', "customer/tickets/{$ticket->public_id}/attachments/{$elsewhere->public_id}", token: $customerToken)->assertNotFound();
        $this->api('DELETE', "customer/tickets/{$ticket->public_id}/attachments/9999999", token: $customerToken)->assertNotFound();
        $this->assertSame(4, $ticket->fresh()->getMedia('attachments')->count());

        $this->api('DELETE', "customer/tickets/{$ticket->public_id}/attachments/{$mine->public_id}", token: $customerToken)->assertNoContent();
        $this->api('DELETE', "technician/tickets/{$ticket->public_id}/attachments/{$theirs->public_id}", token: $technicianToken)->assertNoContent();

        $this->assertSame(['staff.png', 'legacy.png'], $ticket->fresh()->getMedia('attachments')->pluck('file_name')->all());
        $this->assertNull(Media::query()->find($mine->id));
        Storage::disk('local')->assertMissing($mine->getPathRelativeToRoot());
        $this->assertSame(2, Activity::query()->where('log_name', 'ticket')->where('description', 'attachment_removed')->count());
    }

    public function test_a_file_stays_once_the_ticket_is_resolved_or_closed(): void
    {
        [$user, $customer] = $this->customerLogin();
        $token = $this->issueToken($user, 'customer');

        foreach (['resolved', 'closed'] as $status) {
            $ticket = $this->ticket($user->company_id, ['customer_id' => $customer->id, 'status' => $status]);
            $media = $ticket->addMediaFromString('png')->usingFileName('late.png')
                ->withCustomProperties(['company_id' => $user->company_id, 'uploaded_by' => $user->id])->toMediaCollection('attachments');

            $this->api('GET', "customer/tickets/{$ticket->public_id}", token: $token)
                ->assertJsonPath('data.attachments.0.mine', true)
                ->assertJsonPath('data.attachments.0.can_delete', false);
            $this->api('DELETE', "customer/tickets/{$ticket->public_id}/attachments/{$media->public_id}", token: $token)->assertForbidden();
            $this->assertNotNull(Media::query()->find($media->id), $status);
        }
    }

    public function test_the_ticket_detail_does_not_query_once_per_file(): void
    {
        // Sanctum re-stamps last_used_at whenever the clock second changes, one extra UPDATE; frozen time keeps it out of the count.
        $this->freezeTime();
        [$user, $customer] = $this->customerLogin();
        $ticket = $this->ticket($user->company_id, ['customer_id' => $customer->id]);
        $token = $this->issueToken($user, 'customer');
        $add = fn (string $name): Media => $ticket->addMediaFromString('png')->usingFileName($name)
            ->withCustomProperties(['company_id' => $user->company_id, 'uploaded_by' => $user->id])->toMediaCollection('attachments');
        $add('one.png');

        $queries = function () use ($ticket, $token): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->api('GET', "customer/tickets/{$ticket->public_id}", token: $token)->assertOk();

            return count(DB::getQueryLog());
        };
        // The first call also warms the permission cache and stamps the token's last use.
        $queries();
        $withOne = $queries();
        foreach (['two.png', 'three.png', 'four.png', 'five.png', 'six.png'] as $name) {
            $add($name);
        }

        $this->assertSame($withOne, $queries());
    }

    public function test_a_retry_with_the_same_key_stores_one_file_even_after_the_response_cache_is_gone(): void
    {
        [$user, $customer] = $this->customerLogin();
        $ticket = $this->ticket($user->company_id, ['customer_id' => $customer->id]);
        $token = $this->issueToken($user, 'customer');
        $key = (string) Str::uuid();
        $uri = "customer/tickets/{$ticket->public_id}/attachments";

        $first = $this->multipart($uri, ['file' => UploadedFile::fake()->image('a.jpg')], $token, $key)->assertCreated();
        $replay = $this->multipart($uri, ['file' => UploadedFile::fake()->image('a.jpg')], $token, $key);
        $this->assertSame($first->getContent(), $replay->getContent());
        $this->assertSame('true', $replay->headers->get('Idempotent-Replayed'));

        Cache::forget(EnforceIdempotency::cacheKey($user->id, $key));
        $this->multipart($uri, ['file' => UploadedFile::fake()->image('a-again.jpg')], $token, $key)
            ->assertCreated()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, $ticket->fresh()->getMedia('attachments')->count());
    }

    public function test_a_user_with_both_roles_is_held_to_the_app_of_the_token(): void
    {
        // A customer who also works as a technician: the customer token reaches the customer's own tickets only.
        [$user] = $this->customerLogin();
        $this->grantRole($user, 'technician');
        $assigned = $this->ticket($user->company_id, ['status' => 'assigned', 'assigned_to' => $user->id, 'customer_id' => Customer::factory()->create(['company_id' => $user->company_id])->id]);

        $this->multipart("customer/tickets/{$assigned->public_id}/attachments", ['file' => UploadedFile::fake()->image('x.jpg')], $this->issueToken($user, 'customer'))
            ->assertNotFound();
        $this->assertSame(0, $assigned->fresh()->getMedia('attachments')->count());
    }

    /** @param  array<string, mixed>  $overrides */
    private function ticket(int $companyId, array $overrides = []): Ticket
    {
        $staff = User::factory()->create(['company_id' => $companyId, 'email_verified_at' => now(), 'is_active' => true]);
        $category = TicketCategory::query()->create([
            'company_id' => $companyId, 'name' => 'Support', 'code' => 'SUP-'.Str::random(6),
            'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true,
        ]);

        return Ticket::query()->create([
            'company_id' => $companyId, 'code' => 'TKT-'.Str::random(10), 'title' => 'Internet down', 'description' => 'Offline',
            'source' => 'customer', 'category_id' => $category->id, 'status' => 'open', 'priority' => 'medium',
            'created_by' => $staff->id, 'sla_deadline' => now()->addDay(), ...$overrides,
        ]);
    }

    private function revokeFromRole(int $companyId, string $role, string $permission): void
    {
        $this->companyRoles($companyId)[$role]->revokePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
