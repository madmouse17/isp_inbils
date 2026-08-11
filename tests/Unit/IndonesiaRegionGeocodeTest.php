<?php

namespace Tests\Unit;

use App\Services\Core\IndonesiaRegionService;
use App\Services\Core\OpenStreetMapGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IndonesiaRegionGeocodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('indonesia_provinces')->insert(['code' => '51', 'name' => 'BALI']);
        DB::table('indonesia_cities')->insert(['code' => '5108', 'province_code' => '51', 'name' => 'KABUPATEN BULELENG']);
        DB::table('indonesia_districts')->insert(['code' => '510804', 'city_code' => '5108', 'name' => 'BANJAR']);
        DB::table('indonesia_villages')->insert(['code' => '5108042001', 'district_code' => '510804', 'name' => 'BANYUSERI']);
    }

    public function test_villages_load_for_six_digit_district_code(): void
    {
        $options = IndonesiaRegionService::options([], [], ['510804']);

        $this->assertSame('5108042001', $options['villages'][0]['code']);
        $this->assertSame('BANYUSERI', $options['villages'][0]['name']);
    }

    public function test_geocoder_maps_latest_result_to_complete_region_hierarchy(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([[
                'display_name' => 'Banyuseri, Banjar, Buleleng, Bali, Indonesia',
                'lat' => '-8.2000000',
                'lon' => '115.1000000',
                'address' => [
                    'state' => 'Bali',
                    'county' => 'Kabupaten Buleleng',
                    'city_district' => 'Kecamatan Banjar',
                    'village' => 'Banyuseri',
                    'postcode' => '81152',
                ],
            ]]),
        ]);

        $result = app(OpenStreetMapGeocoder::class)->search('Banyuseri Banjar Bali')[0];

        $this->assertSame('51', $result['province_code']);
        $this->assertSame('5108', $result['city_code']);
        $this->assertSame('510804', $result['district_code']);
        $this->assertSame('5108042001', $result['village_code']);
        $this->assertSame('81152', $result['postal_code']);
    }

    public function test_geocoder_failure_returns_empty_results(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([], 503)]);

        $this->assertSame([], app(OpenStreetMapGeocoder::class)->search('Unavailable address'));
    }
}
