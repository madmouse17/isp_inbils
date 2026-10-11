<?php

namespace Tests\Feature\WhatsApp;

use App\Actions\SetCustomerWhatsAppConsentAction;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\ServiceSubscription;
use App\Models\Core\WhatsAppMessage;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\CustomerService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingService;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Services\SpkService;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\InteractsWithWhatsApp;

/**
 * What the per-ticket suites do not own: every event through its real trigger, the privacy of every store
 * a message can leave a trace in, and two companies that share a customer's phone number.
 *
 * `fire()` is the whole matrix: ten messages for nine events, each through the domain call that sends it
 * (recurring batch, BillingService::send, the reminder command, recordPayment, TicketService create /
 * assign / resolve, SpkService::assign to the technician and to the customer, the access link).
 */
class WhatsAppPrivacyAndTenantTest extends TestCase
{
    use InteractsWithWhatsApp;
    use RefreshDatabase;

    private const PHONE = '0812-3456-7890';

    private const PHONE_E164 = '6281234567890';

    private const TECHNICIAN_PHONE = '0813-5555-0001';

    /**
     * The messages `fire()` sends, in order: event, approved template, parameters in the template, whether a
     * customer is the recipient.
     */
    private const FIRED = [
        ['invoice_issued', 'tagihan_terbit', 5, true],
        ['invoice_issued', 'tagihan_terbit', 5, true],
        ['invoice_reminder', 'tagihan_pengingat', 5, true],
        ['payment_received', 'pembayaran_diterima', 5, true],
        ['ticket_created', 'tiket_dibuat', 3, true],
        ['ticket_assigned', 'tiket_ditangani', 4, true],
        ['ticket_resolved', 'tiket_selesai', 4, true],
        ['spk_scheduled', 'spk_penugasan_teknisi', 4, false],
        ['spk_scheduled_customer', 'spk_jadwal_pelanggan', 4, true],
        ['access_link_notice', 'akses_portal_info', 2, true],
    ];

    private int $sequence = 0;

    /** @var array{0: string|false, 1: string|false}|null */
    private ?array $phpTraceSettings = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
        $this->enableWhatsApp();
        $this->fakeWhatsApp();
        $this->travelTo('2026-10-14 03:00:00'); // 10:00 in Jakarta, outside the quiet hours
    }

    protected function tearDown(): void
    {
        if ($this->phpTraceSettings !== null) {
            ini_set('zend.exception_ignore_args', (string) $this->phpTraceSettings[0]);
            ini_set('zend.exception_string_param_max_len', (string) $this->phpTraceSettings[1]);
        }

        parent::tearDown();
    }

    // --- the matrix: every event through its trigger ------------------------------------------------------------

    public function test_every_event_sends_exactly_one_message_with_its_template_to_the_right_person(): void
    {
        $w = $this->world('PT Contoh Net');

        $this->fire($w);

        $requests = $this->graphRequests();
        $rows = $this->whatsAppRows($w['company']->id);
        $this->assertCount(count(self::FIRED), $requests, 'one Graph call per message');
        $this->assertCount(count(self::FIRED), $rows, 'one row per message');

        foreach (self::FIRED as $i => [$event, $template, $parameters, $toCustomer]) {
            $data = $requests[$i]->data();
            $row = $rows[$i];

            $this->assertSame($toCustomer ? self::PHONE_E164 : '6281355550001', $data['to'], "$event goes to its audience");
            $this->assertSame('whatsapp', $data['messaging_product']);
            $this->assertSame('template', $data['type']);
            $this->assertSame($template, $data['template']['name'], "$event uses its approved template");
            $this->assertSame(['code' => 'id'], $data['template']['language']);
            $this->assertCount($parameters, $data['template']['components'][0]['parameters'], "$event fills every placeholder");
            $this->assertSame(['Bearer '.self::WA_TOKEN], $requests[$i]->header('Authorization'));

            $this->assertSame($event, $row->event);
            $this->assertSame($template, $row->template);
            $this->assertSame('id', $row->language);
            $this->assertSame('sent', $row->status);
            $this->assertNull($row->skip_reason);
            $this->assertNotNull($row->provider_message_id);
            $this->assertSame($toCustomer ? 'customer' : 'user', $row->recipient_type);
            $this->assertSame($toCustomer ? $w['customer']->id : $w['technician']->id, $row->recipient_id);
            $this->assertSame($toCustomer ? '7890' : '0001', $row->recipient_last4);
        }

        $this->assertSame($rows->pluck('dedupe_key')->unique()->count(), $rows->count(), 'every message owns its idempotency key');
    }

    /** @return array<string, array{0: string, 1: ?string, 2: ?string}> gate => the gate, the reason for the customer's messages, the reason for the technician's */
    public static function gates(): array
    {
        return [
            'no credentials configured' => ['no credentials configured', 'disabled', 'disabled'],
            'company switch off' => ['company switch off', 'disabled', 'disabled'],
            'every event switched off' => ['every event switched off', 'event_disabled', 'event_disabled'],
            'no consent recorded' => ['no consent recorded', 'no_consent', null],
            'consent withdrawn' => ['consent withdrawn', 'opted_out', null],
            'customer landline' => ['customer landline', 'invalid_phone', null],
            'technician landline' => ['technician landline', null, 'invalid_phone'],
            'monthly cap reached' => ['monthly cap reached', 'budget_exceeded', 'budget_exceeded'],
        ];
    }

    #[DataProvider('gates')]
    public function test_every_gate_skips_every_event_it_blocks_with_its_reason_and_calls_nothing_for_them(string $gate, ?string $customerReason, ?string $technicianReason): void
    {
        $w = $this->world('PT Contoh Net');
        $this->closeGate($gate, $w);

        $this->fire($w);

        $rows = $this->whatsAppRows($w['company']->id);
        $this->assertCount(count(self::FIRED), $rows, 'a blocked message still leaves its row');
        $sent = 0;

        foreach (self::FIRED as $i => [$event, , , $toCustomer]) {
            $reason = $toCustomer ? $customerReason : $technicianReason;

            if ($reason === null) {
                $sent++;
                $this->assertSame('sent', $rows[$i]->status, "$event is not blocked by $gate");

                continue;
            }

            $this->assertSame('skipped', $rows[$i]->status, "$event is blocked by $gate");
            $this->assertSame($reason, $rows[$i]->skip_reason, "$event is blocked by $gate");
            $this->assertNull($rows[$i]->provider_message_id);
        }

        $this->assertCount($sent, $this->graphRequests(), 'a blocked message makes no Graph call');
    }

    public function test_a_company_with_a_cap_and_a_switched_off_event_never_changes_what_another_company_sends(): void
    {
        $a = $this->world('PT Contoh Net', self::PHONE, self::TECHNICIAN_PHONE);
        $b = $this->world('CV Lain Net', self::PHONE, '0813-5555-0002');
        $b['company']->forceFill(['settings' => array_merge($b['company']->settings ?? [], ['whatsapp_monthly_cap' => 1, 'whatsapp_events' => ['ticket_created', 'ticket_resolved']])])->save();

        $this->fire($a);
        $this->fire($b);

        $this->assertSame(['sent'], $this->whatsAppRows($a['company']->id)->pluck('status')->unique()->values()->all(), "B's cap and event list do not touch A, and A's ten sent messages do not count against B's cap");
        $mine = $this->whatsAppRows($b['company']->id);
        $this->assertSame(['sent', 'skipped'], $mine->pluck('status')->unique()->sort()->values()->all());
        $this->assertSame(1, $mine->where('status', 'sent')->count(), "B's cap of one counts B's own messages only");
        $this->assertSame('ticket_created', $mine->firstWhere('status', 'sent')->event);
        $this->assertSame(['budget_exceeded'], $mine->where('event', 'ticket_resolved')->pluck('skip_reason')->all());
        $this->assertSame(['event_disabled'], $mine->where('event', 'payment_received')->pluck('skip_reason')->all());
    }

    public function test_every_event_survives_a_transient_failure_and_the_retry_sends_it_exactly_once(): void
    {
        config(['queue.default' => 'database']);
        $attempts = [];
        $this->whatsAppReply = function (Request $request) use (&$attempts) {
            $message = json_encode($request->data()['template']['components']); // the parameters tell the messages apart
            $attempts[$message] = ($attempts[$message] ?? 0) + 1;

            return $attempts[$message] === 1
                ? Http::response(['error' => ['code' => 2, 'message' => '(#2) Service temporarily unavailable']], 500)
                : Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.'.Str::random(24)]]]);
        };
        $w = $this->world('PT Contoh Net');

        $this->fire($w);
        $this->runWorkerUntilIdle();

        $this->assertCount(2 * count(self::FIRED), $this->graphRequests(), 'each message went to Meta twice: the failed call and its retry');
        $rows = $this->whatsAppRows();
        $this->assertCount(count(self::FIRED), $rows, 'the retry reuses the message row');
        $this->assertSame(['sent'], $rows->pluck('status')->unique()->values()->all());
        $this->assertSame([null], $rows->pluck('error_code')->unique()->values()->all());
        $this->assertSame(count(self::FIRED), $rows->pluck('provider_message_id')->unique()->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_consent_withdrawn_and_a_technician_deactivated_while_the_jobs_wait_are_honoured_when_they_run(): void
    {
        config(['queue.default' => 'database']);
        $w = $this->world('PT Contoh Net');

        $this->fire($w);
        $this->assertSame(count(self::FIRED), DB::table('jobs')->where('queue', 'whatsapp')->count(), 'every message waits in the queue');

        SetCustomerWhatsAppConsentAction::execute($w['customer'], false, $w['admin']);
        EmployeeProfile::withoutCompany()->where('user_id', $w['technician']->id)->update(['status' => 'inactive']);
        $this->runWorkerUntilIdle();

        Http::assertNothingSent();
        $rows = $this->whatsAppRows();
        $this->assertCount(count(self::FIRED), $rows);
        $this->assertSame(['skipped'], $rows->pluck('status')->unique()->values()->all());
        $this->assertSame(['opted_out'], $rows->where('recipient_type', 'customer')->pluck('skip_reason')->unique()->values()->all(), 'consent is read when the job runs, not when it was queued');
        $this->assertSame(['no_consent'], $rows->where('recipient_type', 'user')->pluck('skip_reason')->all(), 'so is the technician\'s active profile');
    }

    public function test_a_rollback_after_a_manual_issue_or_a_payment_sends_nothing(): void
    {
        $w = $this->world('PT Contoh Net');
        $draft = $this->invoice($w, 'ROLL-1', 'draft', 125000, '2026-10-20');
        $open = $this->invoice($w, 'ROLL-2', 'sent', 250000, '2026-10-17');
        $this->actingAs($w['admin']);

        CompanyService::runAs($w['company']->id, function () use ($draft, $open): void {
            try {
                DB::transaction(function () use ($draft, $open): void {
                    BillingService::send($draft);
                    BillingService::recordPayment($open, 50000, 'cash');

                    throw new RuntimeException('abort after both');
                });
            } catch (RuntimeException) {
            }
        });

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('sent', $open->fresh()->status);
        $this->assertSame(0, DB::table('payments')->count());
        Http::assertNothingSent();
        $this->assertCount(0, $this->whatsAppRows());
    }

    // --- privacy: nothing personal in any store, whatever the provider answers -----------------------------------

    /** @return array<string, array{0: string}> */
    public static function providerAnswers(): array
    {
        return [
            'Meta accepts' => ['accepted'],
            'Meta has a transient error' => ['transient'],
            'Meta rejects the message for good' => ['permanent'],
            'the call times out' => ['timeout'],
        ];
    }

    #[DataProvider('providerAnswers')]
    public function test_no_event_leaves_a_phone_number_its_parameters_or_a_token_in_any_store(string $answer): void
    {
        // The production image has no php.ini, so stack traces carry their arguments (a trace is what
        // failed_jobs.exception and the exception log lines are made of).
        $this->useProductionStackTraceSettings();
        config(['queue.default' => 'database']);
        $logs = $this->captureLogs();
        $sent = $this->metaAnswers($answer);
        $w = $this->world('PT Contoh Net');
        $activitySince = (int) Activity::query()->max('id');

        $this->fire($w);

        $queued = DB::table('jobs')->where('queue', 'whatsapp')->count();
        $this->assertSame(count(self::FIRED), $queued, 'the real queue holds one job per message');
        $this->assertCount(0, $this->whatsAppRows(), 'nothing is sent before a worker takes the jobs');
        $jobsBeforeWorker = DB::table('jobs')->get();

        $this->runWorkerUntilIdle();

        $this->assertCount(
            ['accepted' => 10, 'permanent' => 10, 'transient' => 50, 'timeout' => 50][$answer],
            $sent(),
            'ten messages: one call each when Meta answers for good, five attempts each when it keeps failing',
        );
        $failed = DB::table('failed_jobs')->where('queue', 'whatsapp')->count();
        in_array($answer, ['transient', 'timeout'], true)
            ? $this->assertSame($queued, $failed, 'a message that keeps failing ends in failed_jobs after its retries')
            : $this->assertSame(0, $failed);
        $this->assertSame(
            ['accepted' => ['sent'], 'transient' => ['queued'], 'permanent' => ['failed'], 'timeout' => ['queued']][$answer],
            $this->whatsAppRows()->pluck('status')->unique()->values()->all(),
        );

        $secrets = [
            self::PHONE, self::PHONE_E164, '081234567890', '+'.self::PHONE_E164,
            self::TECHNICIAN_PHONE, '081355550001', '6281355550001',
            self::WA_TOKEN, self::WA_APP_SECRET, self::WA_VERIFY_TOKEN,
        ];
        $rendered = collect($sent())->flatMap(fn (array $body): array => array_column($body['template']['components'][0]['parameters'] ?? [], 'text'))->unique()->values()->all();
        $this->assertContains('PT Contoh Net', $rendered);

        $everywhere = [
            'whatsapp_messages' => $this->dump(DB::table('whatsapp_messages')->get()),
            'jobs before the worker' => $this->dump($jobsBeforeWorker),
            'jobs' => $this->dump(DB::table('jobs')->get()),
            'failed_jobs' => $this->dump(DB::table('failed_jobs')->get()),
            'notifications' => $this->dump(DB::table('notifications')->get()),
            'activity_log' => $this->dump(DB::table('activity_log')->where('id', '>', $activitySince)->get()),
            'log output' => $this->logText($logs),
        ];
        $ownedByWhatsApp = [
            'whatsapp_messages' => $everywhere['whatsapp_messages'],
            'WhatsApp jobs before the worker' => $this->dump($jobsBeforeWorker->where('queue', 'whatsapp')),
            'WhatsApp failed_jobs' => $this->dump(DB::table('failed_jobs')->where('queue', 'whatsapp')->get()),
            'whatsapp audit entries' => $this->dump(DB::table('activity_log')->where('id', '>', $activitySince)->where('log_name', 'whatsapp')->get()),
            'log output' => $everywhere['log output'],
        ];

        $this->assertNotSame('[]', $everywhere['whatsapp_messages'], 'the message log was written');
        $this->assertNotSame('[]', $everywhere['activity_log'], 'the domain audit entries were written');
        $this->assertNothingFoundIn($everywhere, $secrets);
        $this->assertNothingFoundIn($ownedByWhatsApp, $rendered);
    }

    public function test_the_message_log_keeps_only_a_keyed_hash_and_the_last_four_digits_of_every_recipient(): void
    {
        $a = $this->world('PT Contoh Net', self::PHONE, self::TECHNICIAN_PHONE);

        $this->fire($a);

        foreach ($this->whatsAppRows() as $row) {
            $phone = $row->recipient_type === 'customer' ? self::PHONE_E164 : '6281355550001';
            $this->assertSame(substr($phone, -4), $row->recipient_last4);
            $this->assertSame(hash_hmac('sha256', $phone, (string) config('app.key')), $row->recipient_hash);
            $this->assertSame(64, strlen((string) $row->recipient_hash));
        }

        $columns = Collection::make(DB::getSchemaBuilder()->getColumnListing('whatsapp_messages'));
        $this->assertSame([], $columns->intersect(['phone', 'to', 'recipient', 'recipient_phone', 'body', 'text', 'message', 'parameters', 'payload', 'content'])->values()->all(), 'no column could hold a number or the rendered text');
    }

    public function test_a_failure_while_handling_a_stop_reply_leaves_no_phone_number_in_the_log(): void
    {
        $this->useProductionStackTraceSettings();
        $logs = $this->captureLogs();
        $w = $this->world('PT Contoh Net');
        Activity::saving(function (Activity $activity): void {
            if ($activity->description === 'opted_out_by_reply') {
                throw new RuntimeException('the audit store is down');
            }
        });

        $this->stopReply('STOP');

        $this->assertNull($w['customer']->fresh()->whatsapp_opt_out_at, 'the withdrawal rolled back with its audit entry');
        $text = $this->logText($logs);
        $this->assertStringContainsString('the audit store is down', $text, 'the failure was logged, so the trace under test exists');
        $this->assertNothingFoundIn(['log output' => $text], [self::PHONE_E164, '081234567890', self::PHONE, '+'.self::PHONE_E164, self::WA_APP_SECRET]);
    }

    // --- tenants: two companies, one phone number -----------------------------------------------------------------

    public function test_two_companies_sharing_a_phone_number_each_get_only_their_own_messages_and_a_stop_reply_silences_both(): void
    {
        $a = $this->world('PT Contoh Net', self::PHONE, self::TECHNICIAN_PHONE);
        $b = $this->world('CV Lain Net', self::PHONE, '0813-5555-0002');

        $this->fire($a);
        $this->fire($b);

        $requests = $this->graphRequests();
        $this->assertCount(2 * count(self::FIRED), $requests);
        foreach ([[$a, 0], [$b, count(self::FIRED)]] as [$world, $offset]) {
            $mine = $requests->slice($offset, count(self::FIRED))->values();
            foreach (self::FIRED as $i => [$event, , , $toCustomer]) {
                $body = $mine[$i]->data();
                if ($toCustomer) {
                    $this->assertSame(self::PHONE_E164, $body['to']);
                    $this->assertSame($world['company']->name, $body['template']['components'][0]['parameters'][0]['text'], "$event names the company that sends it");
                }
            }
            $this->assertSame($world['technician_e164'], $mine[7]->data()['to'], "the technician of {$world['company']->name} is the one told");

            $rows = $this->whatsAppRows($world['company']->id);
            $this->assertCount(count(self::FIRED), $rows);
            $this->assertSame([(int) $world['company']->id], $rows->pluck('company_id')->unique()->values()->all());
            $this->assertSame([$world['customer']->id], $rows->where('recipient_type', 'customer')->pluck('recipient_id')->unique()->values()->all());
            $this->assertSame([$world['technician']->id], $rows->where('recipient_type', 'user')->pluck('recipient_id')->unique()->values()->all());
        }

        foreach ([[$a, $b], [$b, $a]] as [$mine, $theirs]) {
            $this->actingAs($mine['admin']);
            $ids = collect($this->get(route('admin.customers.show', $mine['customer']))->assertOk()->inertiaProps('whatsappMessages'))->pluck('id')->sort()->values()->all();
            $this->assertSame(
                $this->whatsAppRows($mine['company']->id)->where('recipient_type', 'customer')->pluck('id')->sort()->values()->all(),
                $ids,
                'the customer page lists the own rows and none of the other company',
            );
            $this->get(route('admin.customers.show', $theirs['customer']))->assertNotFound();
        }

        $this->stopReply('Stop')->assertOk();

        foreach ([$a, $b] as $world) {
            $this->assertNotNull($world['customer']->fresh()->whatsapp_opt_out_at, 'a STOP from the shared number withdraws every company\'s consent');
        }
        $audit = Activity::query()->where('log_name', 'whatsapp')->where('description', 'opted_out_by_reply')->orderBy('company_id')->get();
        $this->assertSame([(int) $a['company']->id, (int) $b['company']->id], $audit->pluck('company_id')->map(fn ($id): int => (int) $id)->all(), 'each withdrawal is audited in its own company');
        $this->assertStringNotContainsString(self::PHONE_E164, $audit->toJson());

        $this->travel(2)->hours();
        $before = $this->graphRequests()->count();
        $this->fire($a, '2026-09');
        $this->fire($b, '2026-09');

        $afterStop = $this->graphRequests()->slice($before);
        $this->assertSame([$a['technician_e164'], $b['technician_e164']], $afterStop->map(fn (Request $request): string => $request->data()['to'])->values()->all(), 'only the technicians, who have no consent to withdraw, are still messaged');
        foreach ([$a, $b] as $world) {
            $later = $this->whatsAppRows($world['company']->id)->slice(count(self::FIRED))->values();
            $this->assertCount(count(self::FIRED), $later);
            foreach (self::FIRED as $i => [$event, , , $toCustomer]) {
                $this->assertSame($toCustomer ? 'skipped' : 'sent', $later[$i]->status, $event);
                $this->assertSame($toCustomer ? 'opted_out' : null, $later[$i]->skip_reason, $event);
            }
        }
    }

    public function test_message_rows_are_invisible_and_unwritable_without_a_company_context(): void
    {
        $w = $this->world('PT Contoh Net');
        $this->fire($w);
        $this->app['auth']->logout();
        CompanyService::useCompanyForTests(null);

        $this->assertSame(0, WhatsAppMessage::query()->count());
        $this->assertSame(count(self::FIRED), WhatsAppMessage::withoutCompany()->count());

        try {
            WhatsAppMessage::query()->create(['recipient_type' => 'customer', 'recipient_id' => 1, 'event' => 'payment_received', 'template' => 'pembayaran_diterima', 'language' => 'id', 'dedupe_key' => 'x:1', 'status' => 'skipped']);
            $this->fail('A row without a company must not be written.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    // --- consent: one writer ---------------------------------------------------------------------------------------

    public function test_consent_cannot_be_mass_assigned_whatever_the_column(): void
    {
        $w = $this->world('PT Contoh Net');
        $stranger = Customer::factory()->create(['company_id' => $w['company']->id, 'phone' => self::PHONE]);
        $forged = ['whatsapp_opt_in_at' => '2020-01-01 00:00:00', 'whatsapp_opt_in_source' => 'import', 'whatsapp_opt_in_by' => $w['admin']->id, 'whatsapp_opt_out_at' => '2020-01-01 00:00:00'];

        $this->actingAs($w['admin']);
        $stranger->fill($forged)->save();
        $stranger->update($forged);
        $created = Customer::query()->create($forged + ['code' => 'CUS-FORGED', 'name' => 'Forged', 'type' => 'Individual', 'email' => 'forged@example.test', 'phone' => self::PHONE, 'is_active' => true]);

        foreach ([$stranger->fresh(), $created->fresh()] as $customer) {
            $this->assertSame([null, null, null, null], array_values($customer->only(array_keys($forged))));
            $this->assertFalse($customer->hasWhatsAppConsent());
        }
        foreach (array_keys($forged) as $column) {
            $this->assertFalse((new Customer())->isFillable($column), "$column must stay out of \$fillable");
        }
    }

    public function test_only_the_staff_action_and_the_stop_handler_write_the_consent_columns(): void
    {
        $writers = [];
        $write = '/[\'"]whatsapp_opt_(?:in_at|in_by|in_source|out_at)[\'"]\s*=>|->whatsapp_opt_(?:in_at|in_by|in_source|out_at)\s*=(?![=>])/';

        foreach ([base_path('app'), ...glob(base_path('Modules/*/app'))] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                $path = str_replace(base_path().'/', '', $file->getPathname());

                // The model only declares its casts and the read helper; everything else that matches is a writer.
                if ($file->getExtension() === 'php' && $path !== 'app/Models/Core/Customer.php' && preg_match($write, (string) file_get_contents($file->getPathname())) === 1) {
                    $writers[] = $path;
                }
            }
        }
        sort($writers);

        $this->assertSame(['app/Actions/SetCustomerWhatsAppConsentAction.php', 'app/Services/WhatsApp/WhatsAppWebhookHandler.php'], $writers, 'consent is recorded by staff through the action, withdrawn by a STOP reply or by the action, and by nothing else');
    }

    public function test_the_stop_reply_only_withdraws_and_never_gives_consent(): void
    {
        $w = $this->world('PT Contoh Net');
        $stranger = Customer::factory()->create(['company_id' => $w['company']->id, 'phone' => '0812-3456-7890']);

        $this->stopReply(' berhenti ')->assertOk();

        $stranger = $stranger->fresh();
        $this->assertNotNull($stranger->whatsapp_opt_out_at);
        $this->assertNull($stranger->whatsapp_opt_in_at, 'a withdrawal never records an agreement');
        $this->assertNull($stranger->whatsapp_opt_in_by);
        $this->assertFalse($stranger->hasWhatsAppConsent());
    }

    // --- fixtures and helpers --------------------------------------------------------------------------------------

    /**
     * A company with the feature on, a consenting customer with a portal user and a subscription, a technician
     * who has an active profile, and a ticket category.
     *
     * @return array{company: Company, admin: User, customer: Customer, portal: User, technician: User, technician_e164: string, category: TicketCategory}
     */
    private function world(string $name, string $customerPhone = self::PHONE, string $technicianPhone = self::TECHNICIAN_PHONE): array
    {
        $company = $this->whatsAppEnabledFor(Company::factory()->create(['name' => $name, 'timezone' => 'Asia/Jakarta', 'is_active' => true]));
        $admin = User::factory()->create(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $admin->assignRole('admin');

        $customer = $this->consentedCustomer($company, $customerPhone);
        $portal = User::factory()->create(['company_id' => $company->id, 'is_active' => true, 'email' => 'pelanggan.'.$company->id.'@example.test']);
        $customer->forceFill(['user_id' => $portal->id])->save();

        $technician = $this->technician($company, $technicianPhone);
        $technician->assignRole('technician');

        ServiceSubscription::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $company->id])->id,
            'installation_address_id' => CustomerAddress::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'label' => 'Main'])->id,
            'status' => 'active',
            'activation_date' => '2026-05-10',
            'mrc_amount' => 300000,
        ]);

        return [
            'company' => $company,
            'admin' => $admin,
            'customer' => $customer,
            'portal' => $portal,
            'technician' => $technician,
            'technician_e164' => '62'.substr(preg_replace('/\D/', '', $technicianPhone), 1),
            'category' => TicketCategory::create(['company_id' => $company->id, 'name' => 'Support', 'code' => 'SUP-'.$company->id, 'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true]),
        ];
    }

    /**
     * Send every message of the matrix through the domain call that sends it, as the company's own staff.
     * The period moves the recurring invoice, so a second round creates new invoices.
     *
     * @param  array{company: Company, admin: User, customer: Customer, portal: User, technician: User, technician_e164: string, category: TicketCategory}  $w
     */
    private function fire(array $w, string $period = '2026-10'): void
    {
        $company = $w['company'];
        $n = ++$this->sequence;

        $this->actingAs($w['admin']);

        CompanyService::runAs($company->id, function () use ($w, $company, $period, $n): void {
            BillingService::generateForPeriod($period, false, $company->id);

            BillingService::send($this->invoice($w, 'MAN-'.$n, 'draft', 125000, '2026-10-20'));

            $open = $this->invoice($w, 'REM-'.$n, 'sent', 250000, '2026-10-17'); // three days from today in Jakarta
            Artisan::call('billing:whatsapp-reminders', ['--company' => $company->id]);
            BillingService::recordPayment($open, 50000, 'cash');

            $ticket = TicketService::create(['title' => 'Internet mati sejak pagi', 'description' => 'Lampu LOS merah', 'source' => 'customer', 'category_id' => $w['category']->id, 'priority' => 'medium', 'customer_id' => $w['customer']->id], $w['admin']->id)->fresh();
            $ticket = TicketService::assign($ticket, $w['technician']->id, $w['admin']->id, null, $ticket->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'));
            $this->actingAs($w['technician']);
            TicketService::startWork($ticket);
            TicketService::resolve($ticket->fresh(), 'Kabel fiber diganti');
            $this->actingAs($w['admin']);

            $workOrder = WorkOrder::forceCreate([
                'company_id' => $company->id,
                'code' => 'SPK-'.$company->id.'-'.$n,
                'type' => 'installation',
                'title' => 'Pasang ONT baru',
                'status' => 'generated',
                'source' => 'manual',
                'priority' => 'medium',
                'created_by' => $w['admin']->id,
                'customer_id' => $w['customer']->id,
                'scheduled_date' => '2026-10-20',
            ]);
            $workOrder = $workOrder->fresh();
            SpkService::assign($workOrder, $w['technician']->id, $w['admin']->id, null, $workOrder->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'));

            CustomerService::sendAccessLink($w['portal']);
        });
    }

    /** @param  array{company: Company, customer: Customer}  $w */
    private function invoice(array $w, string $number, string $status, int $total, string $dueDate): Invoice
    {
        return InvoiceFactory::new()->create([
            'company_id' => $w['company']->id,
            'customer_id' => $w['customer']->id,
            'number' => 'INV-'.$w['company']->id.'-'.$number,
            'status' => $status,
            'total' => $total,
            'paid_amount' => 0,
            'due_date' => $dueDate,
        ]);
    }

    /** @param  array{company: Company, customer: Customer, technician: User}  $w */
    private function closeGate(string $gate, array $w): void
    {
        $settings = fn (array $changes) => $w['company']->forceFill(['settings' => array_merge($w['company']->settings ?? [], $changes)])->save();
        $profile = fn (array $changes) => EmployeeProfile::withoutCompany()->where('user_id', $w['technician']->id)->firstOrFail()->forceFill($changes)->save();

        match ($gate) {
            'no credentials configured' => $this->disableWhatsApp(),
            'company switch off' => $settings(['whatsapp_enabled' => false]),
            'every event switched off' => $settings(['whatsapp_events' => []]),
            'no consent recorded' => $w['customer']->forceFill(['whatsapp_opt_in_at' => null, 'whatsapp_opt_in_source' => null])->save(),
            'consent withdrawn' => $w['customer']->forceFill(['whatsapp_opt_out_at' => now()])->save(),
            'customer landline' => $w['customer']->forceFill(['phone' => '021-5551234'])->save(),
            'technician landline' => $profile(['phone' => '021-5551234']),
            'monthly cap reached' => $settings(['whatsapp_monthly_cap' => 0]),
        };
    }

    /** A signed STOP reply from the shared number, the way Meta delivers it: no session, no user, no company. */
    private function stopReply(string $text): TestResponse
    {
        $this->app['auth']->logout();
        CompanyService::useCompanyForTests(null);
        [$body, $server] = $this->signedWebhook($this->textWebhook(self::PHONE_E164, $text));

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], $server, $body);
    }

    /**
     * Make Meta answer the way the case says, remembering every request body it was sent. Returns the reader.
     *
     * @return callable(): list<array<string, mixed>>
     */
    private function metaAnswers(string $answer): callable
    {
        $seen = [];
        $this->whatsAppReply = function (Request $request) use (&$seen, $answer) {
            $seen[] = $request->data();

            return match ($answer) {
                'accepted' => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['input' => $request->data()['to'], 'wa_id' => $request->data()['to']]], 'messages' => [['id' => 'wamid.'.Str::random(24)]]]),
                'transient' => Http::response(['error' => ['code' => 2, 'message' => '(#2) Service temporarily unavailable', 'error_data' => ['details' => 'to '.$request->data()['to']]]], 500),
                'permanent' => Http::response(['error' => ['code' => 131026, 'message' => '(#131026) Message undeliverable', 'error_data' => ['details' => 'recipient '.$request->data()['to']]]], 400),
                'timeout' => throw new ConnectionException('cURL error 28: Operation timed out sending to '.$request->data()['to'].' with '.$request->header('Authorization')[0]),
            };
        };

        return function () use (&$seen): array {
            return $seen;
        };
    }

    /** What the worker of the real queue does: take every due job, retry the failed ones after their backoff. */
    private function runWorkerUntilIdle(): void
    {
        foreach (range(1, 6) as $_) {
            Artisan::call('queue:work', ['--queue' => 'whatsapp,default', '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096]);

            if (DB::table('jobs')->doesntExist()) {
                return;
            }

            $this->travel(61)->minutes(); // past the longest backoff (one hour)
        }
    }

    /** PHP without a php.ini, as in the production image: arguments and short strings stay in stack traces. */
    private function useProductionStackTraceSettings(): void
    {
        $this->phpTraceSettings = [ini_get('zend.exception_ignore_args'), ini_get('zend.exception_string_param_max_len')];
        ini_set('zend.exception_ignore_args', '0');
        ini_set('zend.exception_string_param_max_len', '15');
    }

    private function captureLogs(): TestHandler
    {
        $handler = new TestHandler();
        Log::channel(config('logging.default'))->getLogger()->pushHandler($handler);

        return $handler;
    }

    /** The log lines as a log file would hold them, stack traces included. */
    private function logText(TestHandler $handler): string
    {
        $formatter = new LineFormatter(null, null, true, true, true);

        return implode("\n", array_map(fn ($record): string => $formatter->format($record), $handler->getRecords()));
    }

    /** @param  iterable<mixed>  $rows */
    private function dump(iterable $rows): string
    {
        return str_replace('\\/', '/', (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, string>  $stores  store name => everything it holds
     * @param  list<string>  $needles
     */
    private function assertNothingFoundIn(array $stores, array $needles): void
    {
        foreach ($stores as $store => $content) {
            $content = str_replace('\\/', '/', $content);

            foreach ($needles as $needle) {
                $at = strpos($content, $needle);

                $this->assertFalse($at !== false, "$store holds `$needle`: ...".($at === false ? '' : substr($content, max(0, $at - 90), 200)).'...');
            }
        }
    }
}
