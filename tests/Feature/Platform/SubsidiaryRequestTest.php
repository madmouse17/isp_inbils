<?php

namespace Tests\Feature\Platform;

use App\Models\Core\Company;
use App\Models\User;
use App\Notifications\Contracts\SentByPlatform;
use App\Notifications\Platform\SubsidiaryRequestedMail;
use App\Notifications\Platform\SubsidiaryRequestRejectedMail;
use App\Services\Core\CompanyHierarchyService;
use App\Services\Core\CompanyMembershipService;
use App\Services\Platform\SubsidiaryRequestService;
use App\Support\AuditEventText;
use App\Support\MailBrand;
use App\Support\PublicId;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Mockery\MockInterface;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * K34: a tenant admin asks the platform operator for a subsidiary, and the operator creates it (which closes the request)
 * or turns it down with a reason (which closes it and mails the person who asked). The request is a platform audit row
 * (OD12), so these tests read the rows: `tenant.subsidiary_requested` and `tenant.subsidiary_request_closed`.
 */
class SubsidiaryRequestTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /** @var array{root: Company, child: Company, admin: User, staff: User, branch: User} */
    private array $alpha;

    /** @var array{root: Company, child: Company, admin: User, staff: User, branch: User} */
    private array $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->buildTenant('ALP', 'Alpha Net');
        $this->beta = $this->buildTenant('BET', 'Beta Link');
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return ['name' => 'Cabang Utara', 'code' => 'CBU', 'note' => 'Wilayah Bekasi, mulai Januari.', ...$overrides];
    }

    /**
     * The next request is a tenant visitor's. A test shares one application instance across requests: the shared props
     * of the platform shell (closures over its request) must not outlive their request into the tenant page that follows.
     */
    private function tenant(): static
    {
        Inertia::flushShared();

        return $this->asTenantVisitor();
    }

    /** The next request is the operator's; the same reason, the other way round (the tenant shell's closures read a user the platform guard answers with). */
    private function operator(bool $confirmed = true): static
    {
        Inertia::flushShared();

        return $this->asOperator($confirmed);
    }

    /** @param  array<string, mixed>  $overrides */
    private function ask(User $user, array $overrides = []): TestResponse
    {
        return $this->tenant()->actingAs($user)->post(route('admin.company.subsidiary-requests.store'), $this->payload($overrides));
    }

    /** @return Collection<int, Activity> */
    private function requests(): Collection
    {
        return Activity::query()->where('log_name', 'platform')->where('description', 'tenant.subsidiary_requested')->orderBy('id')->get();
    }

    /** @return Collection<int, Activity> */
    private function closings(): Collection
    {
        return Activity::query()->where('log_name', 'platform')->where('description', 'tenant.subsidiary_request_closed')->orderBy('id')->get();
    }

    /**
     * The request a tenant admin filed through the form.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function filed(User $admin, array $overrides = []): Activity
    {
        $before = $this->requests()->count();
        $this->ask($admin, $overrides)->assertSessionHasNoErrors();
        $this->assertSame($before + 1, $this->requests()->count());

        return $this->requests()->last();
    }

    private function reject(Company $root, Activity $request, string $reason = 'Nama sudah dipakai cabang lain.'): TestResponse
    {
        return $this->operator()->post(route('platform.companies.subsidiary-requests.reject', [$root, $request->public_id]), ['reason' => $reason]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function create(Company $root, ?Activity $request, array $overrides = []): TestResponse
    {
        return $this->operator(false)->post(route('platform.companies.subsidiaries.store', $root), [
            'name' => 'Cabang Utara',
            'code' => 'CBU',
            ...($request === null ? [] : ['subsidiary_request_id' => $request->public_id]),
            ...$overrides,
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // The tenant files a request
    // ---------------------------------------------------------------------------------------------

    public function test_an_admin_of_a_top_level_company_files_a_request_and_every_active_operator_is_told(): void
    {
        Notification::fake();
        $second = $this->platformAdmin();
        $this->platformAdmin(['is_active' => false]);

        $this->ask($this->alpha['admin'])->assertRedirect()->assertSessionHas('success', 'Permintaan anak perusahaan dikirim ke operator.');

        $row = $this->requests()->sole();
        $this->assertSame($this->alpha['root']->id, $row->company_id);
        $this->assertSame(Company::class, $row->subject_type);
        $this->assertSame($this->alpha['root']->id, (int) $row->subject_id);
        $this->assertTrue($row->causer->is($this->alpha['admin']));
        // The JSON column keeps its keys in its own order: what counts is the set.
        $this->assertEquals([
            'name' => 'Cabang Utara',
            'code' => 'CBU',
            'note' => 'Wilayah Bekasi, mulai Januari.',
            'requested_by' => $this->alpha['admin']->id,
        ], $row->properties->all());
        $this->assertSame('Anak perusahaan Cabang Utara diminta', AuditEventText::text($row));

        $routes = [];
        Notification::assertSentOnDemand(SubsidiaryRequestedMail::class, function (SubsidiaryRequestedMail $mail, array $channels, AnonymousNotifiable $to) use (&$routes, $row): bool {
            $routes[] = $to->routes['mail'];

            return $mail->companyId === $this->alpha['root']->id && $mail->requestId === $row->public_id && $channels === ['mail'];
        });
        $this->assertEqualsCanonicalizing([
            [$this->operator->email => $this->operator->name],
            [$second->email => $second->name],
        ], $routes, 'one mail per active operator, the inactive one is left out');
    }

    public function test_the_operator_mail_is_a_platform_mail_that_names_the_tenant_and_links_to_its_page(): void
    {
        $sent = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent): void {
            $sent[] = $event->message;
        });
        $this->alpha['root']->forceFill(['name' => 'Alpha [Net](https://evil.example/login)'])->save();

        $request = $this->filed($this->alpha['admin'], ['name' => 'Cabang [Utara](https://evil.example/pay)', 'note' => "Satu baris\n\n# Judul\n[Klik](https://evil.example/x)"]);

        $this->assertCount(1, $sent, 'the one active operator got one mail');
        /** @var Email $email */
        $email = $sent[0];
        $html = (string) $email->getHtmlBody();

        $this->assertSame($this->operator->email, $email->getTo()[0]->getAddress());
        $this->assertSame('Permintaan anak perusahaan dari Alpha [Net](https://evil.example/login)', $email->getSubject());
        $this->assertStringContainsString(route('platform.companies.show', ['company' => $this->alpha['root'], 'permintaan' => $request->public_id]), htmlspecialchars_decode($html));
        $this->assertStringContainsString('Cabang', $html);
        $this->assertStringNotContainsString('href="https://evil.example', $html, 'what the tenant typed never becomes a link');
        $this->assertStringNotContainsString('<h1>Judul', $html, 'nor a block of its own');
        $this->assertStringContainsString('Inbils. Hak cipta dilindungi.', $html, 'the platform signs it, not the tenant');
        $this->assertStringContainsString('Diminta oleh: '.$this->alpha['admin']->name, $html, 'the operator sees who to talk to');
    }

    public function test_both_mails_leave_from_the_platform_mailer_whatever_the_company_set_up(): void
    {
        $asked = new SubsidiaryRequestedMail($this->alpha['root']->id, 'x');

        $this->assertInstanceOf(SentByPlatform::class, $asked);
        $this->assertSame(['name' => 'Inbils', 'logo_url' => null], MailBrand::for(new AnonymousNotifiable(), $asked));
        $this->assertTrue(MailBrand::sentByPlatform($asked));
        $this->assertTrue(MailBrand::sentByPlatform(new SubsidiaryRequestRejectedMail($this->alpha['root']->id, 'x')));
    }

    public function test_a_mail_that_cannot_be_queued_is_reported_and_never_undoes_the_request(): void
    {
        Exceptions::fake();
        $this->mock(Dispatcher::class, function (MockInterface $dispatcher): void {
            $dispatcher->shouldReceive('send')->once()->andThrow(new RuntimeException('queue is down'));
        });

        $this->ask($this->alpha['admin'])->assertRedirect()->assertSessionHas('success')->assertSessionHasNoErrors();

        $this->assertCount(1, $this->requests(), 'the row is the record');
        Exceptions::assertReported(fn (RuntimeException $error): bool => $error->getMessage() === 'queue is down');
    }

    public function test_a_name_and_a_code_are_one_line_and_the_code_is_never_checked_against_taken_codes(): void
    {
        Notification::fake();

        // BET is taken: a tenant must not learn which codes exist. The operator settles the final one.
        $this->ask($this->alpha['admin'], ['name' => "  Cabang \n  Utara  ", 'code' => ' BET ', 'note' => ''])->assertSessionHasNoErrors();

        $row = $this->requests()->sole();
        $this->assertSame('Cabang Utara', $row->properties->get('name'));
        $this->assertSame('BET', $row->properties->get('code'));
        $this->assertNull($row->properties->get('note'));
    }

    public function test_the_form_is_validated(): void
    {
        Notification::fake();
        // Nine posts from one person: this test is about the rules, the throttle has its own.
        $this->withoutMiddleware(ThrottleRequests::class);
        $invalid = [
            'a name is needed' => [['name' => ''], 'name'],
            'a name has three letters at least' => [['name' => 'Ab'], 'name'],
            'a name has a hundred at most' => [['name' => str_repeat('a', 101)], 'name'],
            'a name is text' => [['name' => ['x']], 'name'],
            'a code has the format of every code' => [['code' => 'bad code!'], 'code'],
            'a code starts with a letter or a digit' => [['code' => '-CBU'], 'code'],
            'a code has fifty at most' => [['code' => str_repeat('A', 51)], 'code'],
            'a note has five hundred at most' => [['note' => str_repeat('n', 501)], 'note'],
        ];

        foreach ($invalid as $case => [$change, $field]) {
            $this->ask($this->alpha['admin'], $change)->assertSessionHasErrors($field);
            $this->assertSame(0, $this->requests()->count(), $case);
        }

        $this->ask($this->alpha['admin'], ['name' => 'Abc', 'code' => null, 'note' => null])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->requests(), 'only a name is required');
    }

    public function test_a_company_has_one_open_request_at_a_time_and_another_company_is_unaffected(): void
    {
        Notification::fake();
        $first = $this->filed($this->alpha['admin']);

        $this->ask($this->alpha['admin'], ['name' => 'Cabang Timur'])
            ->assertSessionHasErrors(['pending' => 'Masih ada permintaan anak perusahaan yang ditinjau operator. Tunggu sampai selesai sebelum mengajukan lagi.']);
        $this->assertSame($first->id, $this->requests()->sole()->id, 'no second row');

        // Another admin of the same company is the same company.
        $other = $this->tenantUser($this->alpha['root'], 'admin');
        $this->ask($other, ['name' => 'Cabang Barat'])->assertSessionHasErrors('pending');
        $this->assertCount(1, $this->requests());

        // Another tenant has its own.
        $this->ask($this->beta['admin'])->assertSessionHasNoErrors();
        $this->assertCount(2, $this->requests());
    }

    public function test_a_closed_request_frees_the_company_to_ask_again(): void
    {
        Notification::fake();
        $first = $this->filed($this->alpha['admin']);
        $this->reject($this->alpha['root'], $first)->assertRedirect();

        $this->ask($this->alpha['admin'], ['name' => 'Cabang Timur'])->assertSessionHasNoErrors();

        $this->assertSame(['Cabang Utara', 'Cabang Timur'], $this->requests()->map(fn (Activity $row): mixed => $row->properties->get('name'))->all());
    }

    public function test_only_a_top_level_company_manager_may_ask(): void
    {
        Notification::fake();
        $childAdmin = $this->tenantUser($this->alpha['child'], 'admin');
        // The head's admin, who works inside the subsidiary for the moment.
        $traveller = $this->tenantUser($this->alpha['root'], 'admin');
        app(CompanyMembershipService::class)->grant($traveller, $this->alpha['child']);
        $this->grantRole($traveller, 'admin', $this->alpha['child']);

        $this->ask($this->alpha['staff'])->assertForbidden();
        $this->ask($this->alpha['branch'])->assertForbidden();
        $this->ask($childAdmin)->assertForbidden();

        $this->tenant()->actingAs($traveller)->post(route('admin.company.switch'), ['company_id' => $this->alpha['child']->public_id])->assertRedirect(route('admin.dashboard'));
        $this->post(route('admin.company.subsidiary-requests.store'), $this->payload())->assertForbidden();

        $this->tenant()->post(route('admin.company.subsidiary-requests.store'), $this->payload())->assertRedirect(route('login'));

        $this->assertSame(0, $this->requests()->count());
        Notification::assertNothingSent();

        // Back in the head he may.
        $this->tenant()->actingAs($traveller)->post(route('admin.company.switch'), ['company_id' => $this->alpha['root']->public_id])->assertRedirect(route('admin.dashboard'));
        $this->post(route('admin.company.subsidiary-requests.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertCount(1, $this->requests());
    }

    public function test_asking_is_throttled_on_its_own_budget(): void
    {
        Notification::fake();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->ask($this->alpha['admin'], ['name' => ''])->assertSessionHasErrors('name');
        }

        $this->ask($this->alpha['admin'], ['name' => ''])->assertStatus(429);
        $this->ask($this->alpha['admin'])->assertStatus(429);
        $this->assertSame(0, $this->requests()->count());

        $this->ask($this->beta['admin'])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->requests(), 'the budget is the person\'s, not everybody\'s');
    }

    public function test_the_profile_offers_the_form_and_then_shows_the_request_that_waits(): void
    {
        Notification::fake();
        $page = fn (User $user): TestResponse => $this->tenant()->actingAs($user)->get(route('admin.company.profile.edit'));

        $page($this->alpha['admin'])->assertOk()->assertInertia(fn ($inertia) => $inertia
            ->component('Admin/Company/Profile')
            ->where('can.requestSubsidiary', true)
            ->where('subsidiaryRequest', null));

        $this->filed($this->alpha['admin']);
        $this->filed($this->beta['admin'], ['name' => 'Cabang Beta', 'code' => 'CBB', 'note' => null]);

        // The tenant reads its own text and nothing more: no id, no requester, nobody who handles it.
        $page($this->alpha['admin'])->assertOk()->assertInertia(fn ($inertia) => $inertia
            ->where('can.requestSubsidiary', true)
            ->where('subsidiaryRequest.name', 'Cabang Utara')
            ->where('subsidiaryRequest.code', 'CBU')
            ->where('subsidiaryRequest.note', 'Wilayah Bekasi, mulai Januari.')
            ->where('subsidiaryRequest.requested_at', fn ($at): bool => is_string($at) && strtotime($at) !== false)
            ->where('subsidiaryRequest', fn ($shown): bool => $shown->keys()->all() === ['name', 'code', 'note', 'requested_at']));

        $page($this->beta['admin'])->assertInertia(fn ($inertia) => $inertia->where('subsidiaryRequest.name', 'Cabang Beta'));
    }

    public function test_a_subsidiary_profile_has_no_form_and_never_shows_the_parents_request(): void
    {
        Notification::fake();
        $this->filed($this->alpha['admin']);
        $childAdmin = $this->tenantUser($this->alpha['child'], 'admin');

        $this->tenant()->actingAs($childAdmin)->get(route('admin.company.profile.edit'))->assertOk()->assertInertia(fn ($inertia) => $inertia
            ->where('can.requestSubsidiary', false)
            ->where('subsidiaryRequest', null));
    }

    // ---------------------------------------------------------------------------------------------
    // The operator reads the requests
    // ---------------------------------------------------------------------------------------------

    public function test_the_operator_sees_the_requests_of_a_tenant_on_its_page_and_every_tenant_on_the_dashboard(): void
    {
        Notification::fake();
        $mine = $this->filed($this->alpha['admin']);
        $theirs = $this->filed($this->beta['admin'], ['name' => 'Cabang Beta', 'code' => null, 'note' => null]);

        $this->operator(false)->get(route('platform.companies.show', $this->alpha['root']))->assertOk()->assertInertia(fn ($page) => $page
            ->has('subsidiaryRequests', 1)
            ->where('subsidiaryRequests.0', [
                'id' => $mine->public_id,
                'name' => 'Cabang Utara',
                'code' => 'CBU',
                'note' => 'Wilayah Bekasi, mulai Januari.',
                'requested_at' => $mine->created_at->toIso8601String(),
                'requester' => ['name' => $this->alpha['admin']->name],
                'company' => ['id' => $this->alpha['root']->public_id, 'name' => 'Alpha Net', 'code' => 'ALP'],
            ]));

        $this->operator(false)->get(route('platform.dashboard'))->assertOk()->assertInertia(fn ($page) => $page
            ->has('subsidiaryRequests', 2)
            ->where('subsidiaryRequests.0.id', $mine->public_id)
            ->where('subsidiaryRequests.1.id', $theirs->public_id)
            ->where('subsidiaryRequests.1.code', null)
            ->where('subsidiaryRequests.1.company.code', 'BET'));
    }

    public function test_a_closed_request_leaves_both_lists(): void
    {
        Notification::fake();
        $request = $this->filed($this->alpha['admin']);
        $this->reject($this->alpha['root'], $request)->assertRedirect();

        $this->operator(false)->get(route('platform.companies.show', $this->alpha['root']))->assertInertia(fn ($page) => $page->has('subsidiaryRequests', 0));
        $this->operator(false)->get(route('platform.dashboard'))->assertInertia(fn ($page) => $page->has('subsidiaryRequests', 0));
    }

    public function test_the_lists_cost_the_same_queries_whatever_they_hold(): void
    {
        Notification::fake();
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->operator(false)->get(route('platform.dashboard'))->assertOk();

            return count(DB::getQueryLog());
        };
        $this->filed($this->alpha['admin']);
        $few = $queries();

        foreach (['GAM', 'DEL', 'EPS'] as $code) {
            $this->filed($this->buildTenant($code, "Tenant $code")['admin']);
        }

        $this->assertSame($few, $queries(), 'no query per request');
    }

    // ---------------------------------------------------------------------------------------------
    // The operator creates the subsidiary
    // ---------------------------------------------------------------------------------------------

    public function test_creating_the_subsidiary_from_a_request_closes_the_request_as_created(): void
    {
        Notification::fake();
        $request = $this->filed($this->alpha['admin']);
        $this->filed($this->beta['admin']);

        // The operator changes the code the tenant suggested: the request is about the name asked for.
        $this->create($this->alpha['root'], $request, ['code' => 'CBU2'])
            ->assertRedirect(route('platform.companies.show', $this->alpha['root']))
            ->assertSessionHas('success', 'Anak perusahaan dibuat. Permintaannya ditutup.');

        $this->assertSame('CBU2', Company::query()->where('parent_company_id', $this->alpha['root']->id)->where('name', 'Cabang Utara')->sole()->code);

        $closing = $this->closings()->sole();
        $this->assertSame($this->alpha['root']->id, $closing->company_id);
        $this->assertTrue($closing->causer->is($this->operator));
        $this->assertEquals([
            'request_id' => $request->public_id,
            'name' => 'Cabang Utara',
            'outcome' => 'created',
            'reason' => null,
        ], $closing->properties->all());
        $this->assertSame('Permintaan anak perusahaan Cabang Utara dibuat', AuditEventText::text($closing));
        $this->assertSame(1, Activity::query()->where('description', 'tenant.subsidiary_created')->count(), 'the creation keeps its own row');

        $this->operator(false)->get(route('platform.dashboard'))->assertInertia(fn ($page) => $page->has('subsidiaryRequests', 1)->where('subsidiaryRequests.0.company.code', 'BET'));
        Notification::assertNotSentTo($this->alpha['admin'], SubsidiaryRequestRejectedMail::class);
    }

    public function test_a_subsidiary_created_without_a_request_leaves_the_requests_alone(): void
    {
        Notification::fake();
        $request = $this->filed($this->alpha['admin']);

        $this->create($this->alpha['root'], null, ['name' => 'Cabang Lain', 'code' => 'LAIN'])
            ->assertSessionHas('success', 'Anak perusahaan dibuat.');

        $this->assertCount(0, $this->closings());
        $this->assertNotNull(app(SubsidiaryRequestService::class)->findOpen($this->alpha['root'], $request->public_id));
    }

    public function test_the_request_a_subsidiary_answers_must_be_an_open_request_of_that_tenant(): void
    {
        Notification::fake();
        $mine = $this->filed($this->alpha['admin']);
        $theirs = $this->filed($this->beta['admin']);
        $cases = [
            'another tenant\'s request' => $theirs->public_id,
            'an id that names no request' => PublicId::new(),
            'something that is no id' => 'abc',
            'the integer id of the row' => (string) $mine->id,
            'a list' => ['x'],
        ];

        foreach ($cases as $case => $id) {
            $this->operator(false)->post(route('platform.companies.subsidiaries.store', $this->alpha['root']), ['name' => 'Cabang Utara', 'code' => 'CBU', 'subsidiary_request_id' => $id])
                ->assertSessionHasErrors('subsidiary_request_id');
            $this->assertDatabaseMissing('companies', ['code' => 'CBU']);
            $this->assertCount(0, $this->closings(), $case);
        }
    }

    public function test_a_request_that_was_closed_cannot_be_answered_again(): void
    {
        Notification::fake();
        $request = $this->filed($this->alpha['admin']);
        $this->create($this->alpha['root'], $request)->assertSessionHasNoErrors();

        $this->create($this->alpha['root'], $request, ['name' => 'Cabang Dua', 'code' => 'CB2'])
            ->assertSessionHasErrors(['subsidiary_request_id' => 'Permintaan tidak ditemukan atau sudah ditutup.']);

        $this->assertDatabaseMissing('companies', ['code' => 'CB2']);
        $this->assertCount(1, $this->closings(), 'one closing row, whoever came second');
    }

    public function test_a_suspended_tenant_creates_nothing_and_its_request_stays_open(): void
    {
        Notification::fake();
        $request = $this->filed($this->alpha['admin']);
        $this->alpha['root']->forceFill(['is_active' => false])->save();

        $this->create($this->alpha['root'], $request)->assertSessionHasErrors('lifecycle');

        $this->assertDatabaseMissing('companies', ['code' => 'CBU']);
        $this->assertCount(0, $this->closings());
    }

    public function test_a_creation_that_fails_leaves_the_request_open(): void
    {
        Notification::fake();
        $request = $this->filed($this->alpha['admin']);
        $this->mock(CompanyHierarchyService::class, function (MockInterface $hierarchy): void {
            $hierarchy->shouldReceive('createSubsidiary')->once()->andThrow(new RuntimeException('disk full'));
        });
        $this->withoutExceptionHandling();

        try {
            $this->create($this->alpha['root'], $request);
            $this->fail('the creation was meant to fail');
        } catch (RuntimeException $error) {
            $this->assertSame('disk full', $error->getMessage());
        }

        $this->assertCount(0, $this->closings(), 'the closing row went back with the rest');
        $this->assertNotNull(app(SubsidiaryRequestService::class)->findOpen($this->alpha['root'], $request->public_id));
    }

    // ---------------------------------------------------------------------------------------------
    // The operator turns it down
    // ---------------------------------------------------------------------------------------------

    public function test_rejecting_closes_the_request_with_the_reason_and_mails_the_person_who_asked(): void
    {
        Notification::fake();
        $request = $this->filed($this->alpha['admin']);
        $other = $this->filed($this->beta['admin']);

        $this->reject($this->alpha['root'], $request, 'Nama sudah dipakai cabang lain.')
            ->assertRedirect(route('platform.companies.show', $this->alpha['root']))
            ->assertSessionHas('success', 'Permintaan ditolak. Alasannya dikirim ke pemohon lewat email.');

        $closing = $this->closings()->sole();
        $this->assertSame($this->alpha['root']->id, $closing->company_id);
        $this->assertTrue($closing->causer->is($this->operator));
        $this->assertEquals([
            'request_id' => $request->public_id,
            'name' => 'Cabang Utara',
            'outcome' => 'rejected',
            'reason' => 'Nama sudah dipakai cabang lain.',
        ], $closing->properties->all());
        $this->assertSame('Permintaan anak perusahaan Cabang Utara ditolak', AuditEventText::text($closing));
        $this->assertDatabaseMissing('companies', ['code' => 'CBU']);

        Notification::assertSentTo($this->alpha['admin'], SubsidiaryRequestRejectedMail::class, fn (SubsidiaryRequestRejectedMail $mail, array $channels): bool => $mail->companyId === $this->alpha['root']->id && $mail->closureId === $closing->public_id && $channels === ['mail']);
        Notification::assertNotSentTo([$this->alpha['staff'], $this->beta['admin']], SubsidiaryRequestRejectedMail::class);
        $this->assertNotNull(app(SubsidiaryRequestService::class)->findOpen($this->beta['root'], $other->public_id), 'the other tenant\'s request is untouched');
    }

    public function test_the_rejection_mail_says_the_reason_in_plain_words(): void
    {
        $sent = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent): void {
            $sent[] = $event->message;
        });
        $request = $this->filed($this->alpha['admin'], ['name' => 'Cabang [Utara](https://evil.example/x)']);
        $sent = [];

        $this->reject($this->alpha['root'], $request, "Kode bentrok.\n\n[Klik di sini](https://evil.example/pay)")->assertRedirect();

        $this->assertCount(1, $sent);
        /** @var Email $email */
        $email = $sent[0];
        $html = (string) $email->getHtmlBody();

        $this->assertSame($this->alpha['admin']->email, $email->getTo()[0]->getAddress());
        $this->assertSame('Permintaan anak perusahaan ditolak', $email->getSubject());
        $this->assertStringContainsString('Kode bentrok.', $html);
        $this->assertStringContainsString('Inbils. Hak cipta dilindungi.', $html, 'the platform answers');
        $this->assertStringNotContainsString('href="https://evil.example', $html);
    }

    public function test_a_requester_who_cannot_be_mailed_is_left_to_the_operator(): void
    {
        Notification::fake();
        $request = $this->filed($this->alpha['admin']);
        $this->alpha['admin']->forceFill(['is_active' => false])->save();

        $this->reject($this->alpha['root'], $request)
            ->assertSessionHas('success', 'Permintaan ditolak. Pemohon tidak bisa dikirimi email, jadi sampaikan alasannya langsung.');

        Notification::assertNotSentTo($this->alpha['admin'], SubsidiaryRequestRejectedMail::class);
        $this->assertCount(1, $this->closings(), 'it is closed all the same');
    }

    public function test_a_reject_needs_the_operators_fresh_step_up_and_a_reason(): void
    {
        Notification::fake();
        $request = $this->filed($this->alpha['admin']);
        $url = route('platform.companies.subsidiary-requests.reject', [$this->alpha['root'], $request->public_id]);

        $this->operator(false)->post($url, ['reason' => 'Nama sudah dipakai.'])->assertRedirect(route('platform.password.confirm'));
        $this->assertCount(0, $this->closings());

        foreach ([null, '', 'Maaf', str_repeat('a', 201), ['x']] as $reason) {
            $this->operator()->post($url, ['reason' => $reason])->assertSessionHasErrors('reason');
        }
        $this->operator()->post($url, [])->assertSessionHasErrors('reason');
        $this->assertCount(0, $this->closings());

        // The test's session outlives its requests: drop the operator's before the visitors knock.
        $this->flushSession();
        $this->tenant()->post($url, ['reason' => 'Nama sudah dipakai.'])->assertRedirect(route('platform.login'));
        $this->actingAs($this->alpha['admin'])->post($url, ['reason' => 'Nama sudah dipakai.'])->assertRedirect(route('platform.login'));
        $this->signInPlatformAdmin($this->operator, ['platform.two_factor_verified_at' => null])->post($url, ['reason' => 'Nama sudah dipakai.'])->assertRedirect(route('platform.login'));
        $this->assertCount(0, $this->closings());
        Notification::assertNotSentTo($this->alpha['admin'], SubsidiaryRequestRejectedMail::class);
    }

    public function test_a_reject_reaches_only_an_open_request_of_the_tenant_in_the_url(): void
    {
        Notification::fake();
        $mine = $this->filed($this->alpha['admin']);
        $theirs = $this->filed($this->beta['admin']);
        $reason = ['reason' => 'Nama sudah dipakai.'];

        // Another tenant's request, an id that names none, a subsidiary in place of the tenant: nothing tells them apart.
        $this->reject($this->alpha['root'], $theirs)->assertNotFound();
        $this->operator()->post(route('platform.companies.subsidiary-requests.reject', [$this->alpha['root'], PublicId::new()]), $reason)->assertNotFound();
        $this->operator()->post(route('platform.companies.subsidiary-requests.reject', [$this->alpha['root'], (string) $mine->id]), $reason)->assertNotFound();
        $this->operator()->post(route('platform.companies.subsidiary-requests.reject', [$this->alpha['child'], $mine->public_id]), $reason)->assertNotFound();
        $this->assertCount(0, $this->closings());

        // A closed one is refused, so the second operator is told instead of closing it twice.
        $this->reject($this->alpha['root'], $mine)->assertRedirect();
        $this->reject($this->alpha['root'], $mine)->assertSessionHasErrors(['subsidiary_request_id' => 'Permintaan ini sudah ditutup. Muat ulang halaman untuk melihat yang terbaru.']);
        $this->assertCount(1, $this->closings());
        Notification::assertSentToTimes($this->alpha['admin'], SubsidiaryRequestRejectedMail::class, 1);
    }

    public function test_two_operators_cannot_close_one_request_twice(): void
    {
        Notification::fake();
        $first = $this->filed($this->alpha['admin']);
        $this->create($this->alpha['root'], $first)->assertSessionHasNoErrors();
        $this->reject($this->alpha['root'], $first)->assertSessionHasErrors('subsidiary_request_id');

        $second = $this->filed($this->beta['admin']);
        $this->reject($this->beta['root'], $second)->assertRedirect();
        $this->create($this->beta['root'], $second, ['code' => 'CBB'])->assertSessionHasErrors('subsidiary_request_id');

        $this->assertSame(['created', 'rejected'], $this->closings()->map(fn (Activity $row): mixed => $row->properties->get('outcome'))->all());
        $this->assertDatabaseMissing('companies', ['code' => 'CBB']);
    }
}
