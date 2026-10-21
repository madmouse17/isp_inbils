<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Platform\PlatformAuditService;
use App\Support\PublicId;
use App\Support\SupportActionText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Platform\TenantFixtures;
use Tests\TestCase;
use Tests\Traits\ParsesCsvDownloads;

/**
 * What a tenant's admin reads for the work of the support team (F11-U01, F11-D03): one Indonesian sentence per action,
 * the same one on the page, in the search and in the export. The sentences name the person the work was about.
 */
class AuditLogSupportTextTest extends TestCase
{
    use ParsesCsvDownloads;
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
        $this->alpha['staff']->forceFill(['name' => 'Budi Santoso'])->save();
        $this->beta['staff']->forceFill(['name' => 'Budi Hartono'])->save();
        $this->actingAs($this->alpha['admin']);
    }

    private function support(string $code, mixed $subject): Activity
    {
        $row = app(PlatformAuditService::class)->support($code, ['user_id' => $subject instanceof User ? $subject->id : null], $subject, $this->operator);
        $this->assertInstanceOf(Activity::class, $row);

        return $row;
    }

    /** @return array<string, string|null> the sentence of each row of the page, by action code */
    private function sentences(array $query = []): array
    {
        $rows = $this->get(route('admin.reports.audit-log', ['log_name' => 'support', ...$query]))->assertOk()->inertiaProps('data.data');

        return collect($rows)->pluck('support_text', 'description')->all();
    }

    /** @return list<string> */
    private function dictionary(): array
    {
        return array_keys((new ReflectionClass(SupportActionText::class))->getConstant('SENTENCES'));
    }

    /** Every action code the platform services write under the support log, read from their source. */
    private function writtenCodes(): array
    {
        $codes = [];

        foreach (['ImpersonationService', 'SupportToolsService', 'TenantExportService', 'TenantLifecycleService'] as $service) {
            $source = (string) file_get_contents(app_path("Services/Platform/{$service}.php"));
            preg_match_all("/->support\(\s*'([a-z_.]+)'|->record\([^)]*?'((?:user|impersonation|tenant)\.[a-z_]+)'/", $source, $matches);
            $codes = [...$codes, ...array_filter([...$matches[1], ...$matches[2]])];
        }

        return array_values(array_unique($codes));
    }

    public function test_every_support_code_has_a_sentence(): void
    {
        $written = $this->writtenCodes();
        $this->assertGreaterThanOrEqual(13, count($written), 'the source scan found the writers');
        $this->assertEqualsCanonicalizing($this->dictionary(), $written, 'every code the services write has a sentence, and no sentence is left without a writer');

        foreach ($this->dictionary() as $code) {
            $row = $this->support($code, $this->alpha['staff']);
            $text = SupportActionText::for($row->load('subject'));

            $this->assertStringStartsWith('Tim dukungan Inbils ', $text, $code);
            $this->assertStringNotContainsString(':name', $text, $code);
            $this->assertStringNotContainsString('—', $text, 'no em dash in a sentence');
        }
    }

    public function test_the_sentence_names_the_person_and_reads_pengguna_when_the_person_is_unknown(): void
    {
        $known = $this->support('user.two_factor_reset', $this->alpha['staff'])->load('subject');
        $this->assertSame('Tim dukungan Inbils mengatur ulang 2FA Budi Santoso', SupportActionText::for($known));

        $leaver = User::factory()->create(['company_id' => $this->alpha['root']->id]);
        $gone = $this->support('impersonation.started', $leaver);
        DB::table('users')->where('id', $leaver->id)->delete();
        $this->assertSame('Tim dukungan Inbils masuk sebagai pengguna', SupportActionText::for($gone->load('subject')), 'the person was deleted since');

        $unloaded = Activity::query()->findOrFail($known->id);
        $this->assertSame('Tim dukungan Inbils mengatur ulang 2FA pengguna', SupportActionText::for($unloaded), 'a row whose subject was not loaded costs no query');

        $company = $this->support('tenant.suspended', $this->alpha['root'])->load('subject');
        $this->assertSame('Tim dukungan Inbils menangguhkan perusahaan ini', SupportActionText::for($company));

        $unknown = $this->support('user.something_new', $this->alpha['staff']);
        $this->assertSame('Akses dukungan oleh tim Inbils', SupportActionText::for($unknown));
    }

    public function test_the_page_carries_the_sentence_of_support_rows_only(): void
    {
        $this->support('user.login_unlocked', $this->alpha['staff']);
        AuditService::log('billing', 'invoice_generated', ['amount' => 1], $this->alpha['staff']);

        $rows = collect($this->get(route('admin.reports.audit-log'))->assertOk()->inertiaProps('data.data'));

        $this->assertSame('Tim dukungan Inbils membuka kunci masuk Budi Santoso', $rows->firstWhere('description', 'user.login_unlocked')['support_text']);
        $this->assertNull($rows->firstWhere('description', 'invoice_generated')['support_text']);
    }

    public function test_search_finds_support_rows_by_visible_text(): void
    {
        $this->support('user.two_factor_reset', $this->alpha['staff']);
        $this->support('tenant.suspended', $this->alpha['root']);
        $this->support('impersonation.started', $this->alpha['admin']);
        AuditService::log('billing', 'invoice_generated', [], $this->alpha['staff']);

        $this->assertSame(['user.two_factor_reset'], array_keys($this->sentences(['search' => 'mengatur ulang'])));
        $this->assertSame(['tenant.suspended'], array_keys($this->sentences(['search' => 'Menangguhkan'])), 'the case does not matter');
        $this->assertSame(['impersonation.started'], array_keys($this->sentences(['search' => 'masuk sebagai'])));
        $this->assertEqualsCanonicalizing(
            ['user.two_factor_reset', 'tenant.suspended', 'impersonation.started'],
            array_keys($this->sentences(['search' => 'tim dukungan'])),
            'the words every sentence starts with find every support row',
        );
        $this->assertSame([], $this->sentences(['search' => 'tidak pernah ditulis']));
    }

    public function test_search_finds_support_rows_by_subject_name(): void
    {
        $this->support('user.two_factor_reset', $this->alpha['staff']);
        $this->support('user.login_unlocked', $this->alpha['admin']);

        $this->assertSame(['user.two_factor_reset'], array_keys($this->sentences(['search' => 'Santoso'])));
        $this->assertSame(['user.two_factor_reset'], array_keys($this->sentences(['search' => 'budi san'])));
        $this->assertSame([], $this->sentences(['search' => 'Hartono']), 'a person of another company is no member of this one');
    }

    public function test_export_writes_the_sentence(): void
    {
        $this->support('user.two_factor_reset', $this->alpha['staff']);
        AuditService::log('billing', 'invoice_generated', [], $this->alpha['staff']);

        $records = collect($this->csvRecords($this->get(route('admin.reports.audit-log.export', ['format' => 'csv']))));

        $support = $records->firstWhere('Aksi', 'user.two_factor_reset');
        $this->assertSame('Tim dukungan Inbils mengatur ulang 2FA Budi Santoso', $support['Deskripsi']);
        $this->assertSame('Tim dukungan Inbils', $support['Pelaku'], 'the operator stays masked');
        $this->assertSame('billing: invoice generated', $records->firstWhere('Aksi', 'invoice_generated')['Deskripsi'], 'a pair the dictionary does not know reads as itself, underscores as spaces');

        $searched = $this->csvRecords($this->get(route('admin.reports.audit-log.export', ['format' => 'csv', 'search' => 'ulang 2FA'])));
        $this->assertSame(['Tim dukungan Inbils mengatur ulang 2FA Budi Santoso'], array_column($searched, 'Deskripsi'), 'the export filters on the same words');
    }

    public function test_other_company_rows_stay_out(): void
    {
        $this->support('user.two_factor_reset', $this->alpha['staff']);
        $this->support('user.two_factor_reset', $this->beta['staff']);
        $this->support('tenant.suspended', $this->beta['root']);

        foreach ([[], ['search' => 'Budi'], ['search' => 'mengatur ulang'], ['search' => 'menangguhkan']] as $query) {
            $rows = collect($this->get(route('admin.reports.audit-log', $query))->assertOk()->inertiaProps('data.data'));

            $this->assertStringNotContainsString('Hartono', (string) json_encode($rows->all()), json_encode($query));
            $this->assertNull($rows->firstWhere('description', 'tenant.suspended'), json_encode($query));
        }

        $csv = $this->get(route('admin.reports.audit-log.export', ['format' => 'csv', 'search' => 'Budi']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Budi Santoso', $csv);
        $this->assertStringNotContainsString('Hartono', $csv);
    }

    public function test_a_row_naming_a_person_of_another_company_is_not_found_by_name(): void
    {
        // Written straight: the app never files another company's person under this company, so the member rule is the guard.
        DB::table('activity_log')->insert([
            'public_id' => PublicId::new(),
            'log_name' => 'support',
            'description' => 'user.two_factor_reset',
            'event' => 'user.two_factor_reset',
            'subject_type' => User::class,
            'subject_id' => $this->beta['staff']->id,
            'company_id' => $this->alpha['root']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([], $this->sentences(['search' => 'Hartono']), 'only the members of this company match by name');
    }
}
