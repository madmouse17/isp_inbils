<?php

namespace Tests\Feature\Api\V1\Shared;

use App\Models\Core\Company;
use App\Services\Core\CompanyLogoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * GET me: the ISP behind the login, as the apps brand themselves after sign-in. The company page's public face and
 * nothing else of the company. The logo is an absolute URL on the public disk (the company logo is public by design),
 * so Image.network loads it without a token.
 */
class MeCompanyTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const KEYS = ['id', 'name', 'logo_url', 'brand_color', 'phone', 'whatsapp', 'email', 'website', 'support_hours'];

    public function test_a_customer_gets_the_branding_and_contacts_of_the_company_and_nothing_else_of_it(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create([
            'name' => 'PT Nusa Net',
            'brand_color' => '#EA580C',
            'phone' => '021-5550100',
            'whatsapp' => '+6281234567890',
            'email' => 'halo@nusa.test',
            'website' => 'https://nusa.test',
            'support_hours' => 'Senin–Sabtu 08.00–20.00',
            'address' => 'Jl. Merdeka 1',
            'settings' => ['whatsapp_enabled' => true],
        ]);
        CompanyLogoService::store($company, UploadedFile::fake()->image('logo.png', 800, 400));
        $logoId = $company->fresh()->getFirstMedia('logo')->id;
        [$user] = $this->customerLogin($company);

        $brand = $this->api('GET', 'me', token: $this->issueToken($user, 'customer'))->assertOk()->json('data.company');

        $this->assertSame(self::KEYS, array_keys($brand));
        $this->assertSame([
            'id' => $company->public_id,
            'name' => 'PT Nusa Net',
            'logo_url' => config('filesystems.disks.public.url')."/companies/{$company->id}/company-logo/{$logoId}/logo.webp",
            'brand_color' => '#EA580C',
            'phone' => '021-5550100',
            'whatsapp' => '+6281234567890',
            'email' => 'halo@nusa.test',
            'website' => 'https://nusa.test',
            'support_hours' => 'Senin–Sabtu 08.00–20.00',
        ], $brand);
    }

    public function test_a_company_that_filled_in_nothing_keeps_every_key_as_null(): void
    {
        $company = Company::factory()->create(['name' => 'PT Polos', 'is_active' => true]);
        [$user] = $this->customerLogin($company);

        $brand = $this->api('GET', 'me', token: $this->issueToken($user, 'customer'))->assertOk()->json('data.company');

        $this->assertSame(self::KEYS, array_keys($brand));
        $this->assertSame(['id' => $company->public_id, 'name' => 'PT Polos'], array_filter($brand, fn (mixed $value): bool => $value !== null));
    }

    public function test_the_technician_app_gets_the_same_company_block(): void
    {
        $company = Company::factory()->create(['is_active' => true, 'brand_color' => '#0F766E', 'support_hours' => 'Setiap hari 07.00–21.00']);
        $technician = $this->technicianLogin($company);

        $brand = $this->api('GET', 'me', token: $this->issueToken($technician, 'technician'))->assertOk()->json('data.company');

        $this->assertSame(self::KEYS, array_keys($brand));
        $this->assertSame('#0F766E', $brand['brand_color']);
        $this->assertSame('Setiap hari 07.00–21.00', $brand['support_hours']);
    }
}
