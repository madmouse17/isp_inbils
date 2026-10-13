<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\Setting;
use App\Models\User;
use App\Services\Core\SettingService;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\IndonesiaRegionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompanyMapDefaultTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
        SettingService::flushAll();

        $this->seedRegion('33', 'JAWA TENGAH', '3372', 'KOTA SURAKARTA', -7.5579, 110.8231);
        $this->seedRegion('31', 'DAERAH KHUSUS IBUKOTA JAKARTA', '3174', 'KOTA ADMINISTRASI JAKARTA SELATAN', -6.2726, 106.8100);

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function seedRegion(string $provinceCode, string $province, string $cityCode, string $city, float $lat, float $long): void
    {
        DB::table('indonesia_provinces')->insertOrIgnore(['code' => $provinceCode, 'name' => $province, 'meta' => '{}']);
        DB::table('indonesia_cities')->insertOrIgnore([
            'code' => $cityCode,
            'province_code' => $provinceCode,
            'name' => $city,
            'meta' => json_encode(['lat' => $lat, 'long' => $long]),
        ]);
    }

    /** @param array<string, mixed> $settings */
    private function save(array $settings): TestResponse
    {
        return $this->actingAs($this->admin)->put(route('admin.company.settings.update'), ['settings' => $settings]);
    }

    private function sharedMap(): TestResponse
    {
        return $this->actingAs($this->admin)->get(route('admin.company.settings.edit'));
    }

    public function test_company_map_falls_back_to_the_system_default_then_to_hardcoded(): void
    {
        $this->sharedMap()->assertInertia(fn (Assert $page) => $page
            ->where('company.map', ['lat' => -2.5489, 'lng' => 118.0149, 'zoom' => 5, 'city_code' => null, 'city_name' => null]));

        Setting::query()->whereIn('key', ['default_map_lat', 'default_map_lng', 'default_map_zoom'])->delete();
        SettingService::flushAll();

        $this->sharedMap()->assertInertia(fn (Assert $page) => $page
            ->where('company.map', ['lat' => -2.5489, 'lng' => 118.0149, 'zoom' => 5, 'city_code' => null, 'city_name' => null]));
    }

    public function test_company_map_reads_the_system_default_setting(): void
    {
        SettingService::set('default_map_lat', '-6.2');
        SettingService::set('default_map_lng', '106.8');
        SettingService::set('default_map_zoom', 9);

        $this->sharedMap()->assertInertia(fn (Assert $page) => $page
            ->where('company.map', ['lat' => -6.2, 'lng' => 106.8, 'zoom' => 9, 'city_code' => null, 'city_name' => null]));
    }

    public function test_company_override_wins_and_resolves_the_city_name(): void
    {
        $this->save(['map_city_code' => '3372', 'map_lat' => '-7.5579', 'map_lng' => '110.8231', 'map_zoom' => '12'])->assertRedirect();

        $this->sharedMap()->assertInertia(fn (Assert $page) => $page
            ->where('company.map', ['lat' => -7.5579, 'lng' => 110.8231, 'zoom' => 12, 'city_code' => '3372', 'city_name' => 'KOTA SURAKARTA'])
            ->where('settings.map_city_code', '3372')
            ->where('settings.map_zoom', '12'));
    }

    public function test_settings_page_lists_cities_by_province_then_name_with_coordinates(): void
    {
        $this->sharedMap()->assertInertia(fn (Assert $page) => $page
            ->where('cities', [
                ['code' => '3174', 'name' => 'KOTA ADMINISTRASI JAKARTA SELATAN', 'province_name' => 'DAERAH KHUSUS IBUKOTA JAKARTA', 'lat' => -6.2726, 'lng' => 106.81],
                ['code' => '3372', 'name' => 'KOTA SURAKARTA', 'province_name' => 'JAWA TENGAH', 'lat' => -7.5579, 'lng' => 110.8231],
            ]));
    }

    public function test_manual_point_without_a_city_is_stored_as_sent(): void
    {
        $this->save(['map_city_code' => null, 'map_lat' => -6.9, 'map_lng' => 107.6, 'map_zoom' => 14])->assertRedirect();

        $this->assertEquals(
            ['map_city_code' => null, 'map_lat' => -6.9, 'map_lng' => 107.6, 'map_zoom' => 14],
            collect($this->company->refresh()->settings)->only(['map_city_code', 'map_lat', 'map_lng', 'map_zoom'])->all(),
        );
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidMapSettings(): array
    {
        return [
            'lat above range' => [['map_lat' => 6.5, 'map_lng' => 107], 'settings.map_lat'],
            'lat below range' => [['map_lat' => -11.5, 'map_lng' => 107], 'settings.map_lat'],
            'lng below range' => [['map_lat' => -6, 'map_lng' => 93.9], 'settings.map_lng'],
            'lng above range' => [['map_lat' => -6, 'map_lng' => 142.1], 'settings.map_lng'],
            'lat not numeric' => [['map_lat' => 'abc', 'map_lng' => 107], 'settings.map_lat'],
            'lat without lng' => [['map_lat' => -6], 'settings.map_lng'],
            'lng without lat' => [['map_lng' => 107], 'settings.map_lat'],
            'unknown city' => [['map_city_code' => '9999'], 'settings.map_city_code'],
            'zoom too low' => [['map_zoom' => 2], 'settings.map_zoom'],
            'zoom too high' => [['map_zoom' => 19], 'settings.map_zoom'],
            'zoom fractional' => [['map_zoom' => 5.5], 'settings.map_zoom'],
        ];
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('invalidMapSettings')]
    public function test_invalid_map_settings_are_rejected(array $settings, string $errorKey): void
    {
        $this->save($settings)->assertSessionHasErrors($errorKey);

        $this->assertArrayNotHasKey('map_lat', $this->company->refresh()->settings ?? []);
    }

    public function test_bounds_are_inclusive(): void
    {
        $this->save(['map_lat' => -11, 'map_lng' => 94, 'map_zoom' => 3])->assertSessionDoesntHaveErrors();
        $this->save(['map_lat' => 6, 'map_lng' => 142, 'map_zoom' => 18])->assertSessionDoesntHaveErrors();
    }

    public function test_demo_seeder_sets_bandung_only_when_the_company_has_no_map(): void
    {
        $this->seed(DemoUserSeeder::class);
        $demo = Company::query()->where('code', 'DEMO')->firstOrFail();

        $this->assertSame('3273', $demo->settings['map_city_code']);
        $this->assertSame(12, $demo->settings['map_zoom']);
        $this->assertEqualsWithDelta(-6.9192, $demo->settings['map_lat'], 0.001);
        $this->assertEqualsWithDelta(107.6366, $demo->settings['map_lng'], 0.001);

        $demo->update(['settings' => array_merge($demo->settings, ['map_city_code' => '3372', 'map_lat' => -7.5579, 'map_lng' => 110.8231, 'map_zoom' => 11])]);
        $this->seed(DemoUserSeeder::class);

        $this->assertSame('3372', $demo->refresh()->settings['map_city_code']);
        $this->assertSame(11, $demo->settings['map_zoom']);
    }

    public function test_region_seeder_is_a_no_op_once_the_regions_are_complete(): void
    {
        $this->assertTrue(IndonesiaRegionSeeder::needsSeeding());

        $now = now();
        $provinces = collect(range(1, 38))->map(fn (int $i): array => ['code' => sprintf('%02d', 50 + $i), 'name' => 'P'.$i, 'meta' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('indonesia_provinces')->insert($provinces->all());
        $this->assertTrue(IndonesiaRegionSeeder::needsSeeding(), 'no villages yet');

        DB::table('indonesia_cities')->insert(['code' => '5101', 'province_code' => '51', 'name' => 'C', 'meta' => '{}']);
        DB::table('indonesia_districts')->insert(['code' => '510101', 'city_code' => '5101', 'name' => 'D', 'meta' => '{}']);
        DB::table('indonesia_villages')->insert(['code' => '5101011001', 'district_code' => '510101', 'name' => 'V', 'meta' => '{}']);
        $before = [DB::table('indonesia_provinces')->count(), DB::table('indonesia_cities')->count()];

        $this->seed(IndonesiaRegionSeeder::class);

        $this->assertFalse(IndonesiaRegionSeeder::needsSeeding());
        $this->assertSame($before, [DB::table('indonesia_provinces')->count(), DB::table('indonesia_cities')->count()]);
    }

    public function test_system_seeder_keeps_an_edited_map_default(): void
    {
        SettingService::set('default_map_zoom', 7);
        $this->seed(SystemSettingSeeder::class);

        $this->assertSame(7, SettingService::get('default_map_zoom'));
    }
}
