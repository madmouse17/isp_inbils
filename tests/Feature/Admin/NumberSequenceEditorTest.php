<?php

namespace Tests\Feature\Admin;

use App\Http\Requests\Admin\UpdateNumberSequenceRequest;
use App\Models\Core\Company;
use App\Models\Core\NumberSequence;
use App\Models\User;
use App\Services\Core\NumberSequenceService;
use App\Support\NumberSequences\NumberSequenceDefinition;
use App\Support\NumberSequences\NumberSequenceRegistry;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class NumberSequenceEditorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantRole($this->admin, 'admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function sequence(string $entityType, ?Company $company = null): NumberSequence
    {
        NumberSequenceService::ensureAll(($company ?? $this->company)->id);

        return NumberSequence::forCompany(($company ?? $this->company)->id)->where('entity_type', $entityType)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'prefix' => 'CUS',
            'template' => '{PREFIX}-{SEQ:5}',
            'next_number' => 1,
            'reset_period' => 'never',
            'allow_manual' => false,
        ], $overrides);
    }

    private function update(NumberSequence $sequence, array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)
            ->from(route('admin.number-sequences.index'))
            ->put(route('admin.number-sequences.update', $sequence->public_id), $this->payload($overrides));
    }

    public function test_index_lists_every_registered_entity_sorted_by_label_with_a_preview(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 05:00:00', 'UTC'));

        $response = $this->actingAs($this->admin)
            ->get(route('admin.number-sequences.index'))
            ->assertOk();

        $response
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/NumberSequences/Index')
                ->has('sequences', 9)
                ->where('sequences.0.entity_type', 'network_asset')
                ->where('sequences.0.preview', 'NA-2026-00001')
                ->where('sequences.4.entity_type', 'customer')
                ->where('sequences.4.prefix', 'CUS')
                ->where('sequences.4.template', '{PREFIX}-{SEQ:5}')
                ->where('sequences.4.next_number', 1)
                ->where('sequences.4.reset_period', 'never')
                ->where('sequences.4.allow_manual', false)
                ->where('sequences.4.preview', 'CUS-00001')
                ->where('sequences.4.max_length', 255)
                ->where('sequences.1.entity_type', 'product')
                ->where('sequences.1.allow_manual', true)
                ->where('sequences.5.preview', 'REQ-20261013-0001')
                ->where('sequences.5.reset_period', 'daily')
                ->where('sequences.8.preview', 'TKT-2026-00001')
                ->where('sequences.8.reset_period', 'yearly')
                ->where('company_code', $this->company->code)
                ->where('can.export', true)
                ->has('tokens', 7)
                ->where('tokens.0.token', '{PREFIX}')
                ->where('tokens.6.token', '{SEQ:5}')
                ->has('tokens.0.description')
                ->whereType('sequences.4.id', 'string')
                ->whereType('sequences.4.updated_at', 'string')
            );

        $this->assertSame(
            ['Aset Jaringan', 'Barang (SKU)', 'Karyawan', 'Langganan', 'Pelanggan', 'Permintaan Barang', 'SPK', 'Tagihan', 'Tiket'],
            array_column($response->viewData('page')['props']['sequences'], 'label'),
        );

        $this->assertSame(9, NumberSequence::forCompany($this->company->id)->count(), 'the page creates the missing rows');
    }

    public function test_index_shows_only_the_rows_of_the_company_and_ignores_unregistered_entities(): void
    {
        $other = Company::factory()->create();
        $this->sequence('invoice', $other)->update(['prefix' => 'XX']);
        NumberSequence::query()->forceCreate([
            'company_id' => $this->company->id, 'entity_type' => 'quote', 'prefix' => 'QTE', 'template' => '{PREFIX}-{SEQ:3}', 'next_number' => 1,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.number-sequences.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sequences', 9)
                ->where('sequences.7.entity_type', 'invoice')
                ->where('sequences.7.prefix', 'INV')
            );
    }

    public function test_index_shows_the_stored_format_and_the_next_code(): void
    {
        $this->sequence('customer')->update(['prefix' => 'PLG', 'template' => '{PREFIX}/{COMPANY}/{SEQ:6}', 'next_number' => 41]);

        $this->actingAs($this->admin)
            ->get(route('admin.number-sequences.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sequences.4.template', '{PREFIX}/{COMPANY}/{SEQ:6}')
                ->where('sequences.4.next_number', 41)
                ->where('sequences.4.preview', 'PLG/'.$this->company->code.'/000041')
            );
    }

    public function test_index_and_update_are_forbidden_without_the_system_setting_permission(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantPermissions($user, ['app.web']);
        $row = $this->sequence('customer');

        $this->actingAs($user)->get(route('admin.number-sequences.index'))->assertForbidden();
        $this->update($row, ['prefix' => 'HACK'], $user)->assertForbidden();

        $this->assertSame('CUS', $row->fresh()->prefix);
    }

    public function test_update_saves_the_five_fields_flashes_and_is_written_to_the_audit_log(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 05:00:00', 'UTC'));
        $row = $this->sequence('customer');

        $this->update($row, [
            'prefix' => 'PLG', 'template' => '{PREFIX}-{YY}{MM}-{SEQ:6}', 'next_number' => 120, 'reset_period' => 'monthly', 'allow_manual' => true,
        ])
            ->assertRedirect(route('admin.number-sequences.index'))
            ->assertSessionHas('success', 'Penomoran diperbarui.')
            ->assertSessionHasNoErrors();

        $row = $row->fresh();
        $this->assertSame(['PLG', '{PREFIX}-{YY}{MM}-{SEQ:6}', 120, 'monthly', true], [$row->prefix, $row->template, $row->next_number, $row->reset_period->value, $row->allow_manual]);
        $this->assertSame('2026-10', $row->period_key, 'a new reset period starts counting in the current period');
        $this->assertSame('PLG-2610-000120', NumberSequenceService::generate('customer', $this->company->id));

        $activity = Activity::query()->where('log_name', 'number_sequence')->where('description', 'updated')->sole();
        $this->assertSame(NumberSequence::class, $activity->subject_type);
        $this->assertSame($row->id, (int) $activity->subject_id);
        $this->assertSame($this->company->id, (int) $activity->company_id);
        $this->assertSame($this->admin->id, (int) $activity->causer_id);
        $this->assertSame('CUS', $activity->properties['old']['prefix']);
        $this->assertSame('PLG', $activity->properties['new']['prefix']);
        $this->assertSame('never', $activity->properties['old']['reset_period']);
        $this->assertSame('monthly', $activity->properties['new']['reset_period']);
        $this->assertSame(120, (int) $activity->properties['new']['next_number']);
        $this->assertArrayNotHasKey('entity_type', $activity->properties['new']);
    }

    public function test_update_moves_the_period_key_only_when_the_template_or_reset_period_changes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 05:00:00', 'UTC'));
        $row = $this->sequence('ticket');
        $this->assertSame('2026', $row->period_key);
        Carbon::setTestNow(Carbon::parse('2027-03-01 05:00:00', 'UTC'));

        $this->update($row, ['prefix' => 'TIK', 'template' => '{PREFIX}-{YYYY}-{SEQ:5}', 'next_number' => 30, 'reset_period' => 'yearly'])->assertSessionHasNoErrors();
        $this->assertSame('2026', $row->fresh()->period_key, 'prefix and number alone leave the stored period alone');

        $this->update($row, ['prefix' => 'TIK', 'template' => '{PREFIX}-{YY}-{SEQ:5}', 'next_number' => 30, 'reset_period' => 'yearly'])->assertSessionHasNoErrors();
        $this->assertSame('2027', $row->fresh()->period_key, 'a new template starts counting in the current period');
        $this->assertSame('TIK-27-00030', NumberSequenceService::generate('ticket', $this->company->id));

        $this->update($row, ['prefix' => 'TIK', 'template' => '{PREFIX}-{YY}-{SEQ:5}', 'next_number' => 40, 'reset_period' => 'never'])->assertSessionHasNoErrors();
        $this->assertNull($row->fresh()->period_key, 'a counter that never resets has no period');
    }

    public function test_update_that_changes_nothing_writes_no_audit_entry(): void
    {
        $row = $this->sequence('customer');

        $this->update($row)->assertSessionHasNoErrors()->assertSessionHas('success', 'Penomoran diperbarui.');

        $this->assertSame(0, Activity::query()->where('log_name', 'number_sequence')->count());
    }

    public function test_update_accepts_a_six_digit_sequence_and_the_largest_number_the_column_holds(): void
    {
        $row = $this->sequence('customer');

        $this->update($row, ['template' => '{PREFIX}-{SEQ:6}', 'next_number' => 999999])->assertSessionHasNoErrors();
        $this->assertSame(999999, $row->fresh()->next_number);

        $this->update($row, ['template' => '{PREFIX}-{SEQ:10}', 'next_number' => 4294967295])->assertSessionHasNoErrors();
        $this->assertSame(4294967295, $row->fresh()->next_number);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> */
    public static function invalidPayloads(): array
    {
        $prefix = 'Awalan hanya boleh berisi huruf, angka, - dan _ (maksimal 20 karakter).';
        $literal = 'Gunakan huruf, angka, tanda - _ / . dan token di dalam kurung kurawal.';
        $seq = 'Format harus memuat tepat satu {SEQ:n}, n antara 1 dan 10.';

        return [
            'prefix blank' => [['prefix' => ''], 'prefix', 'Awalan wajib diisi.'],
            'prefix with a space' => [['prefix' => 'CU S'], 'prefix', $prefix],
            'prefix with a slash' => [['prefix' => 'A/B'], 'prefix', $prefix],
            'prefix too long' => [['prefix' => str_repeat('A', 21)], 'prefix', $prefix],
            'template blank' => [['template' => ''], 'template', 'Format wajib diisi.'],
            'template too long' => [['template' => str_repeat('A', 100).'{SEQ:3}'], 'template', 'Format maksimal 100 karakter.'],
            'template without a sequence' => [['template' => '{PREFIX}'], 'template', $seq],
            'template with two sequences' => [['template' => '{SEQ:3}-{SEQ:3}'], 'template', $seq],
            'sequence of 11 digits' => [['template' => '{PREFIX}-{SEQ:11}'], 'template', 'Token {SEQ:11} tidak dikenal.'],
            'unknown token' => [['template' => '{NOPE}-{SEQ:3}'], 'template', 'Token {NOPE} tidak dikenal.'],
            'template with a space' => [['template' => 'A B-{SEQ:3}'], 'template', $literal],
            'year token missing for a yearly reset' => [['reset_period' => 'yearly'], 'template', 'Reset berkala memerlukan {YYYY} atau {YY}.'],
            'month token missing for a monthly reset' => [['template' => '{PREFIX}-{YYYY}-{SEQ:5}', 'reset_period' => 'monthly'], 'template', 'Reset bulanan atau harian memerlukan {MM}.'],
            'day token missing for a daily reset' => [['template' => '{PREFIX}-{YYYY}{MM}-{SEQ:5}', 'reset_period' => 'daily'], 'template', 'Reset harian memerlukan {DD}.'],
            'next number zero' => [['next_number' => 0], 'next_number', 'Nomor berikutnya minimal 1.'],
            'next number not a number' => [['next_number' => 'abc'], 'next_number', 'Nomor berikutnya harus berupa angka bulat.'],
            'next number blank' => [['next_number' => ''], 'next_number', 'Nomor berikutnya wajib diisi.'],
            'next number wider than the sequence' => [['next_number' => 100000], 'next_number', 'Nomor berikutnya maksimal 99999 untuk {SEQ:5}.'],
            'next number past the column' => [['template' => '{PREFIX}-{SEQ:10}', 'next_number' => 4294967296], 'next_number', 'Nomor berikutnya terlalu besar.'],
            'unknown reset period' => [['reset_period' => 'weekly'], 'reset_period', 'Pilihan reset penomoran tidak valid.'],
            'reset period blank' => [['reset_period' => ''], 'reset_period', 'Reset penomoran wajib dipilih.'],
            'manual switch not a boolean' => [['allow_manual' => 'maybe'], 'allow_manual', 'Pilihan input manual tidak valid.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_update_rejects_invalid_input_with_an_indonesian_message_on_the_field(array $overrides, string $field, string $message): void
    {
        $row = $this->sequence('customer');

        $this->update($row, $overrides)->assertSessionHasErrors([$field => $message]);

        $fresh = $row->fresh();
        $this->assertSame(['CUS', '{PREFIX}-{SEQ:5}', 1, 'never', false], [$fresh->prefix, $fresh->template, $fresh->next_number, $fresh->reset_period->value, $fresh->allow_manual], 'a refused edit changes nothing');
    }

    public function test_update_rejects_a_format_whose_longest_code_does_not_fit_the_column(): void
    {
        app(NumberSequenceRegistry::class)->register(new NumberSequenceDefinition('probe_short', 'Probe', 'customers', 'code', 10, 'PRB', '{PREFIX}-{SEQ:3}'));
        $row = NumberSequence::factory()->create([
            'company_id' => $this->company->id, 'entity_type' => 'probe_short', 'prefix' => 'PRB', 'template' => '{PREFIX}-{SEQ:3}',
        ]);

        $this->update($row, ['prefix' => 'PRB', 'template' => '{PREFIX}-{YYYY}-{SEQ:5}'])
            ->assertSessionHasErrors(['template' => 'Format menghasilkan kode lebih dari 10 karakter.']);
        $this->update($row, ['prefix' => 'PRB', 'template' => '{PREFIX}-{SEQ:7}'])
            ->assertSessionHasErrors(['template' => 'Format menghasilkan kode lebih dari 10 karakter.']);
        $this->update($row, ['prefix' => 'PRB', 'template' => '{PREFIX}-{SEQ:6}'])->assertSessionHasNoErrors();

        $this->assertSame('{PREFIX}-{SEQ:6}', $row->fresh()->template, 'a code of exactly the column length fits');
    }

    public function test_the_prefix_rule_refuses_a_trailing_newline(): void
    {
        $rules = ['prefix' => (new UpdateNumberSequenceRequest())->rules()['prefix']];

        $this->assertTrue(Validator::make(['prefix' => 'CUS_1-A'], $rules)->passes());
        $this->assertTrue(Validator::make(['prefix' => str_repeat('A', 20)], $rules)->passes());
        $this->assertFalse(Validator::make(['prefix' => "CUS\n"], $rules)->passes());
    }

    public function test_update_of_another_company_row_or_an_unregistered_entity_is_not_found(): void
    {
        $other = Company::factory()->create();
        $foreign = $this->sequence('customer', $other);
        $quote = NumberSequence::query()->forceCreate([
            'company_id' => $this->company->id, 'entity_type' => 'quote', 'prefix' => 'QTE', 'template' => '{PREFIX}-{SEQ:3}', 'next_number' => 1,
        ]);

        $this->update($foreign, ['prefix' => 'HACK'])->assertNotFound();
        $this->update($quote, ['prefix' => 'HACK'])->assertNotFound();

        $this->assertSame('CUS', $foreign->fresh()->prefix);
        $this->assertSame('QTE', $quote->fresh()->prefix);
    }

    public function test_the_next_code_follows_the_saved_format(): void
    {
        $row = $this->sequence('customer');

        $this->update($row, ['prefix' => 'PLG', 'template' => '{PREFIX}/{COMPANY}/{SEQ:6}', 'next_number' => 15])->assertSessionHasNoErrors();

        $this->assertSame('PLG/'.$this->company->code.'/000015', NumberSequenceService::peek('customer', $this->company->id));
        $this->assertSame('PLG/'.$this->company->code.'/000015', NumberSequenceService::generate('customer', $this->company->id));
        $this->assertSame('PLG/'.$this->company->code.'/000016', NumberSequenceService::generate('customer', $this->company->id));
    }

    public function test_update_cannot_move_a_row_to_another_company_or_entity_or_set_its_period(): void
    {
        $other = Company::factory()->create();
        $row = $this->sequence('customer');

        $this->update($row, ['prefix' => 'PLG', 'company_id' => $other->id, 'entity_type' => 'product', 'period_key' => '1999', 'id' => 999999])
            ->assertSessionHasNoErrors();

        $fresh = $row->fresh();
        $this->assertSame([$row->id, $this->company->id, 'customer', null, 'PLG'], [$fresh->id, (int) $fresh->company_id, $fresh->entity_type, $fresh->period_key, $fresh->prefix]);

        NumberSequenceService::update($row, ['prefix' => 'SVC', 'company_id' => $other->id, 'entity_type' => 'product']);

        $fresh = $row->fresh();
        $this->assertSame([$this->company->id, 'customer', 'SVC'], [(int) $fresh->company_id, $fresh->entity_type, $fresh->prefix], 'the service takes the same five fields only');
    }
}
