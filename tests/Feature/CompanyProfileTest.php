<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_logo_upload_is_resized_and_converted_to_webp(): void
    {
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $admin->assignRole('admin');
        CompanyService::resetCache();

        $response = $this->actingAs($admin)->post(route('admin.company.profile.update'), [
            '_method' => 'put',
            'name' => 'Updated Company',
            'code' => 'UPD',
            'logo' => UploadedFile::fake()->image('logo.png', 1200, 600),
        ]);

        $response->assertRedirect();

        $media = $company->fresh()->getFirstMedia('logo');

        $this->assertNotNull($media);

        $path = $media->getPathRelativeToRoot();
        Storage::disk('public')->assertExists($path);

        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));

        $this->assertStringStartsWith('companies/'.$company->id.'/company-logo/'.$media->id.'/', $path);
        $this->assertSame('logo.webp', $media->file_name);
        $this->assertLessThanOrEqual(512, $width);
        $this->assertLessThanOrEqual(512, $height);
    }

    public function test_company_logo_upload_is_limited_to_10_mb(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $admin->assignRole('admin');
        CompanyService::resetCache();

        $response = $this->actingAs($admin)->post(route('admin.company.profile.update'), [
            '_method' => 'put',
            'name' => 'Updated Company',
            'code' => 'UPD',
            'logo' => UploadedFile::fake()->image('logo.png')->size(10241),
        ]);

        $response->assertInvalid(['logo']);
    }

    public function test_company_logo_upload_rejects_unsafe_mime_type(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $admin->assignRole('admin');
        CompanyService::resetCache();

        $response = $this->actingAs($admin)->post(route('admin.company.profile.update'), [
            '_method' => 'put',
            'name' => 'Updated Company',
            'code' => 'UPD',
            'logo' => UploadedFile::fake()->create('malware.exe', 1, 'application/x-msdownload'),
        ]);

        $response->assertInvalid(['logo']);
    }
}
