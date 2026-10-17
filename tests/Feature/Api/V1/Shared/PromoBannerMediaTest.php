<?php

namespace Tests\Feature\Api\V1\Shared;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Service\Models\PromoBanner;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

class PromoBannerMediaTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAAEElEQVR42mNk+M/wn4GBgQEAGQID/df43NwAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_matching_customer_can_fetch_banner_media_but_other_company_and_hidden_banner_cannot(): void
    {
        [$user, $customer] = $this->customerLogin();
        [$foreignUser] = $this->customerLogin(Company::factory()->create(['is_active' => true]));
        $banner = CompanyService::runAs((int) $customer->company_id, fn () => PromoBanner::factory()->published()->create());
        $media = $banner->addMediaFromString(base64_decode(self::PNG))->usingFileName('banner.png')
            ->withCustomProperties(['company_id' => $customer->company_id])->toMediaCollection('promo_banner');

        $this->api('GET', "media/{$media->public_id}", token: $this->issueToken($user, 'customer'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->api('GET', "media/{$media->public_id}", token: $this->issueToken($foreignUser, 'customer'))->assertNotFound();

        $banner->forceFill(['is_active' => false])->save();
        $this->api('GET', "media/{$media->public_id}", token: $this->issueToken($user, 'customer'))->assertNotFound();
    }
}
