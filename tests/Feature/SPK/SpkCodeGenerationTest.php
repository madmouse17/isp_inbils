<?php

namespace Tests\Feature\SPK;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SPK\Models\WorkOrder;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class SpkCodeGenerationTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    public function test_store_generates_unique_spk_codes(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);

        $payload = [
            'type' => 'maintenance',
            'title' => 'SPK First',
            'description' => 'first',
        ];

        $this->post(route('admin.spk.store'), $payload)->assertRedirect(route('admin.spk.index'));

        $payload['title'] = 'SPK Second';
        $this->post(route('admin.spk.store'), $payload)->assertRedirect(route('admin.spk.index'));

        $codes = WorkOrder::query()->orderBy('id')->pluck('code')->all();

        $this->assertCount(2, $codes);
        $this->assertSame(array_values(array_unique($codes)), $codes);
        $this->assertStringStartsWith('SPK-'.now()->year.'-', $codes[0]);
        $this->assertStringStartsWith('SPK-'.now()->year.'-', $codes[1]);
        $this->assertNotSame($codes[0], $codes[1]);
    }
}
