<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\CustomerAccountCreated;
use App\Services\Core\CompanyService;
use App\Services\Core\CustomerService;
use App\Services\WhatsApp\WhatsAppTransientError;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\AllowsManualCodes;
use Tests\Traits\InteractsWithWhatsApp;

class WhatsAppAccessLinkNoticeTest extends TestCase
{
    use AllowsManualCodes;
    use InteractsWithWhatsApp;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
        $this->company = Company::factory()->create(['name' => 'PT Contoh Net', 'is_active' => true]);
        $this->whatsAppEnabledFor($this->company);
        $this->enableWhatsApp();
        $this->fakeWhatsApp();
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($this->admin, 'admin');
        $this->setManualCodes($this->company->id, true, 'customer');
        $this->travelTo('2026-10-14 03:00:00');
    }

    /** @return Collection<int, mixed> the mails the array mailer holds */
    private function mails(): Collection
    {
        return Mail::mailer()->getSymfonyTransport()->messages();
    }

    /**
     * A customer with a portal user, created the way onboarding does: the consent is recorded in the same
     * transaction as the account, so it is there when the access link goes out after commit.
     */
    private function customerWithPortalUser(bool $consent = true): Customer
    {
        $this->actingAs($this->admin);

        $customer = DB::transaction(function () use ($consent): Customer {
            $customer = CustomerService::createWithUser(['code' => 'CUS-LINK', 'name' => 'Budi Santoso', 'type' => 'Individual', 'email' => 'budi.santoso@example.test', 'phone' => '0812-3456-7890', 'is_active' => true]);

            if ($consent) {
                $customer->forceFill(['whatsapp_opt_in_at' => now(), 'whatsapp_opt_in_source' => 'staff'])->save();
            }

            return $customer;
        });

        return Customer::query()->with('user')->findOrFail($customer->id);
    }

    /** @return list<string> */
    private function noticeParameters(): array
    {
        return array_column($this->graphRequests()->sole()->data()['template']['components'][0]['parameters'], 'text');
    }

    public function test_a_consenting_customer_gets_the_mail_and_a_whatsapp_notice_without_any_link(): void
    {
        $this->actingAs($this->admin)->post(route('admin.customers.store'), $this->customerOnboardingPayload($this->company, ['whatsapp_opt_in' => true, 'email' => 'budi.santoso@example.test']))->assertRedirect();

        $this->assertCount(1, $this->mails());
        $this->assertSame('Akun pelanggan Anda sudah dibuat', $this->mails()->sole()->getOriginalMessage()->getSubject());
        $this->assertCount(1, $this->graphRequests());
        Http::assertSent(fn (Request $request): bool => $request->data()['template']['name'] === 'akses_portal_info' && $request->data()['to'] === '6281234567890');
        $this->assertSame(['PT Contoh Net', 'b***@example.test'], $this->noticeParameters());

        $sent = json_encode($this->graphRequests()->sole()->data());
        foreach (['http', 'reset-password', 'token', 'budi.santoso@example.test'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sent);
        }
        $row = $this->whatsAppRows()->sole();
        $this->assertSame('sent', $row->status);
        $this->assertSame('access_link_notice', $row->event);
        $this->assertSame('customer', $row->recipient_type);
        $this->assertSame(Customer::query()->where('code', 'CUS-WA')->value('id'), $row->recipient_id);
    }

    public function test_the_emailed_token_still_resets_the_password_after_the_whatsapp_job_ran(): void
    {
        $customer = $this->customerWithPortalUser();
        $this->assertCount(1, $this->graphRequests(), 'the notice sent at creation');
        $this->travelTo('2026-10-14 05:30:00');
        Queue::fake();

        CustomerService::sendAccessLink($customer->user);

        $jobs = Queue::pushed(SendQueuedNotifications::class);
        $mailJob = $jobs->first(fn (SendQueuedNotifications $job): bool => $job->channels === ['mail']);
        $whatsAppJob = $jobs->first(fn (SendQueuedNotifications $job): bool => $job->channels === [WhatsAppChannel::class]);
        $this->assertCount(2, $jobs);

        $mailJob->handle(app(ChannelManager::class));
        $tokens = DB::table('password_reset_tokens')->where('email', $customer->user->email)->get();
        $this->assertCount(1, $tokens);
        $this->assertSame(1, preg_match('#/reset-password/([^?"]+)\?email=#', (string) $this->mails()->last()->getOriginalMessage()->getHtmlBody(), $match));

        $whatsAppJob->handle(app(ChannelManager::class));

        $this->assertCount(2, $this->graphRequests(), 'the notice for the new hour went out');
        $this->assertEquals($tokens, DB::table('password_reset_tokens')->where('email', $customer->user->email)->get(), 'the WhatsApp path must not mint a token');
        $this->assertTrue(Password::broker()->tokenExists($customer->user, $match[1]));
    }

    public function test_a_customer_without_consent_gets_the_mail_only_and_a_skipped_row(): void
    {
        $customer = $this->customerWithPortalUser(consent: false);

        $this->assertCount(1, $this->mails());
        Http::assertNothingSent();
        $row = $this->whatsAppRows()->sole();
        $this->assertSame('skipped', $row->status);
        $this->assertSame('no_consent', $row->skip_reason);
        $this->assertSame($customer->id, $row->recipient_id);
    }

    public function test_three_resends_within_the_hour_send_three_mails_and_no_second_notice(): void
    {
        $customer = $this->customerWithPortalUser();
        $mailsBefore = $this->mails()->count();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($this->admin)->post(route('admin.customers.access-link', $customer))->assertRedirect()->assertSessionHas('success');
        }

        $this->assertSame(3, $this->mails()->count() - $mailsBefore);
        $this->assertCount(1, $this->graphRequests());
        $this->assertCount(1, $this->whatsAppRows());
    }

    public function test_the_next_hour_gets_a_new_notice(): void
    {
        $customer = $this->customerWithPortalUser();
        $this->assertCount(1, $this->graphRequests());

        $this->travelTo('2026-10-14 03:40:00');
        CustomerService::sendAccessLink($customer->user);
        $this->assertCount(1, $this->graphRequests(), 'the same hour');

        $this->travelTo('2026-10-14 04:05:00');
        CustomerService::sendAccessLink($customer->user);

        $this->assertCount(2, $this->graphRequests());
        $this->assertCount(2, $this->whatsAppRows());
    }

    public function test_a_retry_across_the_hour_boundary_reuses_the_row_of_the_first_attempt(): void
    {
        $customer = $this->customerWithPortalUser();
        $this->travelTo('2026-10-14 05:59:00');
        Queue::fake();
        CustomerService::sendAccessLink($customer->user);
        $notice = Queue::pushed(SendQueuedNotifications::class)->first(fn (SendQueuedNotifications $job): bool => $job->channels === [WhatsAppChannel::class]);
        $key = 'access_link_notice:'.$customer->user->id.':2026-10-14-05';

        $this->travelTo('2026-10-14 05:59:30');
        $this->whatsAppReplies(500, ['error' => ['code' => 2, 'message' => '(#2) Service temporarily unavailable']]);
        try {
            $notice->handle(app(ChannelManager::class));
            $this->fail('the failure must reach the queue so that it retries');
        } catch (WhatsAppTransientError) {
        }
        $this->assertSame(['queued'], $this->whatsAppRows()->where('dedupe_key', $key)->pluck('status')->all());

        $this->travelTo('2026-10-14 06:05:00'); // the queue retries after its backoff, in the next hour
        $this->whatsAppReply = null;
        $notice->handle(app(ChannelManager::class));

        $this->assertSame(['sent'], $this->whatsAppRows()->where('dedupe_key', $key)->pluck('status')->all(), 'the retry takes the row of the first attempt');
        $this->assertSame(0, $this->whatsAppRows()->where('status', 'queued')->count(), 'no attempt is left stuck in flight');
        $this->assertSame(0, $this->whatsAppRows()->filter(fn ($row): bool => str_ends_with($row->dedupe_key, '-06'))->count(), 'the hour the retry ran in has no row of its own');
    }

    public function test_only_a_customers_portal_user_gets_the_whatsapp_channel(): void
    {
        $customer = $this->customerWithPortalUser();
        $notification = new CustomerAccountCreated($this->company->id);

        $this->assertSame(['mail', WhatsAppChannel::class], $notification->via($customer->user));
        $this->assertSame(['mail'], $notification->via($this->admin));
    }

    public function test_the_channel_choice_does_not_need_a_company_context(): void
    {
        $customer = $this->customerWithPortalUser();
        auth()->logout();
        CompanyService::resetCache();

        $this->assertNull(CompanyService::currentId());
        $this->assertSame(['mail', WhatsAppChannel::class], (new CustomerAccountCreated($this->company->id))->via($customer->user));
    }

    public function test_the_whatsapp_job_is_routed_to_its_queue_the_mail_keeps_the_default_one_and_neither_holds_the_email(): void
    {
        $customer = $this->customerWithPortalUser();
        Queue::fake();

        CustomerService::sendAccessLink($customer->user);

        $jobs = Queue::pushed(SendQueuedNotifications::class);
        $this->assertSame(['whatsapp'], $jobs->filter(fn (SendQueuedNotifications $job): bool => $job->channels === [WhatsAppChannel::class])->pluck('queue')->all());
        $this->assertSame([null], $jobs->filter(fn (SendQueuedNotifications $job): bool => $job->channels === ['mail'])->pluck('queue')->all());
        foreach ($jobs as $job) {
            $this->assertStringNotContainsString('budi.santoso', serialize($job));
            $this->assertSame($this->company->id, $job->notification->companyId);
        }
    }
}
