<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
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
        $this->grantRole($admin, 'admin');
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
        $this->grantRole($admin, 'admin');
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
        $this->grantRole($admin, 'admin');
        CompanyService::resetCache();

        $response = $this->actingAs($admin)->post(route('admin.company.profile.update'), [
            '_method' => 'put',
            'name' => 'Updated Company',
            'code' => 'UPD',
            'logo' => UploadedFile::fake()->create('malware.exe', 1, 'application/x-msdownload'),
        ]);

        $response->assertInvalid(['logo']);
    }

    public function test_company_phone_only_accepts_supported_characters(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantRole($admin, 'admin');
        CompanyService::resetCache();

        $this->actingAs($admin)->post(route('admin.company.profile.update'), [
            '_method' => 'put',
            'name' => $company->name,
            'code' => $company->code,
            'phone' => '+62(21)-5550100',
        ])->assertRedirect();

        $this->assertSame('+62(21)-5550100', $company->fresh()->phone);

        $this->actingAs($admin)->post(route('admin.company.profile.update'), [
            '_method' => 'put',
            'name' => $company->name,
            'code' => $company->code,
            'phone' => '0812ABC',
        ])->assertInvalid(['phone']);
    }

    public function test_branding_fields_are_saved_and_reach_the_profile_page(): void
    {
        $this->withoutVite();
        [$admin, $company] = $this->adminOfNewCompany();

        $this->actingAs($admin)->post(route('admin.company.profile.update'), $this->profile($company, [
            'brand_color' => '#EA580C',
            'whatsapp' => '+6281234567890',
            'support_hours' => 'Senin–Sabtu 08.00–20.00',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'brand_color' => '#EA580C',
            'whatsapp' => '+6281234567890',
            'support_hours' => 'Senin–Sabtu 08.00–20.00',
        ]);

        $this->actingAs($admin)->get(route('admin.company.profile.edit'))->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Company/Profile')
            ->where('company.data.brand_color', '#EA580C')
            ->where('company.data.whatsapp', '+6281234567890')
            ->where('company.data.support_hours', 'Senin–Sabtu 08.00–20.00'));
    }

    public function test_branding_fields_can_be_cleared_and_stay_when_the_form_does_not_send_them(): void
    {
        [$admin, $company] = $this->adminOfNewCompany();
        $company->forceFill(['brand_color' => '#2563EB', 'whatsapp' => '0811111', 'support_hours' => 'Setiap hari'])->save();

        $this->actingAs($admin)->post(route('admin.company.profile.update'), $this->profile($company))->assertRedirect();
        $this->assertSame(['#2563EB', '0811111', 'Setiap hari'], $this->branding($company));

        $this->actingAs($admin)->post(route('admin.company.profile.update'), $this->profile($company, ['brand_color' => '', 'whatsapp' => '', 'support_hours' => '']))->assertRedirect();
        $this->assertSame([null, null, null], $this->branding($company));
    }

    public function test_brand_color_is_a_hash_and_six_hex_digits(): void
    {
        [$admin, $company] = $this->adminOfNewCompany();

        foreach (['#2563EB', '#ea580c', '#0F766e'] as $valid) {
            $this->actingAs($admin)->post(route('admin.company.profile.update'), $this->profile($company, ['brand_color' => $valid]))->assertSessionHasNoErrors();
            $this->assertSame($valid, $company->fresh()->brand_color);
        }

        foreach (['2563EB', '#25F', '#GGGGGG', 'red', '#2563EB1', '#2563E', 'rgb(1,2,3)', '##2563EB'] as $invalid) {
            $this->actingAs($admin)->post(route('admin.company.profile.update'), $this->profile($company, ['brand_color' => $invalid]))->assertInvalid(['brand_color']);
        }

        $this->assertSame('#0F766e', $company->fresh()->brand_color);
    }

    public function test_whatsapp_follows_the_phone_pattern_and_support_hours_are_limited_to_120_characters(): void
    {
        [$admin, $company] = $this->adminOfNewCompany();

        foreach (['0812ABC', '08 12 345', 'wa.me/62812'] as $invalid) {
            $this->actingAs($admin)->post(route('admin.company.profile.update'), $this->profile($company, ['whatsapp' => $invalid]))->assertInvalid(['whatsapp']);
        }
        $this->actingAs($admin)->post(route('admin.company.profile.update'), $this->profile($company, ['whatsapp' => str_repeat('1', 51)]))->assertInvalid(['whatsapp']);
        $this->actingAs($admin)->post(route('admin.company.profile.update'), $this->profile($company, ['support_hours' => str_repeat('é', 121)]))->assertInvalid(['support_hours']);

        $this->actingAs($admin)->post(route('admin.company.profile.update'), $this->profile($company, ['whatsapp' => str_repeat('1', 50), 'support_hours' => str_repeat('é', 120)]))->assertSessionHasNoErrors();
        $this->assertSame(120, mb_strlen((string) $company->fresh()->support_hours));
    }

    public function test_the_validation_messages_of_the_branding_fields_are_indonesian(): void
    {
        [$admin, $company] = $this->adminOfNewCompany();

        $errors = $this->actingAs($admin)->post(route('admin.company.profile.update'), $this->profile($company, ['brand_color' => 'red', 'whatsapp' => '08ab', 'support_hours' => str_repeat('a', 121)]))
            ->assertInvalid(['brand_color', 'whatsapp', 'support_hours'])
            ->baseResponse->getSession()->get('errors')->getBag('default')->toArray();

        $this->assertSame(['Warna merek harus berupa kode warna heksadesimal seperti #2563EB.'], $errors['brand_color']);
        $this->assertSame(['Nomor WhatsApp hanya boleh berisi angka dan karakter ( ) + -.'], $errors['whatsapp']);
        $this->assertStringContainsString('jam layanan', $errors['support_hours'][0]);
    }

    /** @return array{0: User, 1: Company} */
    private function adminOfNewCompany(): array
    {
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $admin = User::factory()->create(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($admin, 'admin');
        CompanyService::resetCache();

        return [$admin, $company];
    }

    /**
     * What the profile form posts: identity first, then whatever the test sets.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function profile(Company $company, array $fields = []): array
    {
        return ['_method' => 'put', 'name' => $company->name, 'code' => $company->code, ...$fields];
    }

    /** @return list<?string> */
    private function branding(Company $company): array
    {
        $fresh = $company->fresh();

        return [$fresh->brand_color, $fresh->whatsapp, $fresh->support_hours];
    }
}
