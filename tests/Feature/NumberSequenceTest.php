<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\NumberSequence;
use App\Models\User;
use App\Services\Core\NumberSequenceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class NumberSequenceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    private User $admin;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        \App\Services\Core\CompanyService::resetCache();
    }

    public function test_first_number_generation(): void
    {
        $this->actingAs($this->admin);
        $code = NumberSequenceService::generate('invoice', 'INV');
        $this->assertStringStartsWith('INV-' . now()->year . '-00001', $code);
    }

    public function test_next_number_increments(): void
    {
        $this->actingAs($this->admin);
        $code1 = NumberSequenceService::generate('invoice', 'INV');
        $code2 = NumberSequenceService::generate('invoice', 'INV');
        $this->assertStringEndsWith('00001', $code1);
        $this->assertStringEndsWith('00002', $code2);
    }

    public function test_different_sequences_do_not_conflict(): void
    {
        $this->actingAs($this->admin);
        $invCode = NumberSequenceService::generate('invoice', 'INV');
        $spkCode = NumberSequenceService::generate('spk', 'SPK');
        $this->assertStringStartsWith('INV', $invCode);
        $this->assertStringStartsWith('SPK', $spkCode);
        // Both should be 00001
        $this->assertStringEndsWith('00001', $invCode);
        $this->assertStringEndsWith('00001', $spkCode);
    }

    public function test_company_scope_isolation(): void
    {
        $this->actingAs($this->admin);
        $code1 = NumberSequenceService::generate('invoice', 'INV');

        $otherCompany = Company::factory()->create();
        $code2 = NumberSequenceService::generate('invoice', 'INV', $otherCompany->id);

        // Both should be 00001 (different companies, independent sequences)
        $this->assertStringEndsWith('00001', $code1);
        $this->assertStringEndsWith('00001', $code2);
    }

    public function test_peek_returns_next_without_consuming(): void
    {
        $this->actingAs($this->admin);
        NumberSequenceService::generate('invoice', 'INV');
        $peeked = NumberSequenceService::peek('invoice');
        $this->assertNotNull($peeked);
        $this->assertStringEndsWith('00002', $peeked);
        // Generate should still be 00002
        $code = NumberSequenceService::generate('invoice', 'INV');
        $this->assertStringEndsWith('00002', $code);
    }

    public function test_index_returns_200(): void
    {
        $this->admin->assignRole('admin');
        $response = $this->actingAs($this->admin)->get(route('admin.number-sequences.index'));
        $response->assertOk();
    }
}
