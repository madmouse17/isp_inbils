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

    public function test_city_in_text_prefers_kabupaten_when_named(): void
    {
        DB::table('indonesia_provinces')->insert([['code' => '32', 'name' => 'JAWA BARAT'], ['code' => '31', 'name' => 'DAERAH KHUSUS IBUKOTA JAKARTA']]);
        DB::table('indonesia_cities')->insert([
            ['code' => '3204', 'province_code' => '32', 'name' => 'KABUPATEN BANDUNG'],
            ['code' => '3217', 'province_code' => '32', 'name' => 'KABUPATEN BANDUNG BARAT'],
            ['code' => '3273', 'province_code' => '32', 'name' => 'KOTA BANDUNG'],
            ['code' => '3174', 'province_code' => '31', 'name' => 'KOTA ADMINISTRASI JAKARTA SELATAN '],
        ]);

        $code = fn (?string $address): ?string => IndonesiaRegionService::cityInText($address)?->code;

        $this->assertSame('3273', $code('Jl. Soekarno Hatta 10, Bandung'), 'a plain name is the Kota');
        $this->assertSame('3273', $code('Jl. Merdeka 5, Kota Bandung 40111'));
        $this->assertSame('3204', $code('Jl. Raya 7, Kab. Bandung'), 'Kab. asks for the Kabupaten');
        $this->assertSame('3204', $code('Jl. Raya 7, Kabupaten Bandung'));
        $this->assertSame('3217', $code('Jl. Raya Lembang 3, Bandung Barat'), 'the longest name wins');
        $this->assertSame('3174', $code('Jl. Mawar 1, jakarta selatan'), 'case, the Kota Administrasi prefix and a stored trailing space do not matter');
        $this->assertSame('5108', $code('Desa Banyuseri, Kecamatan Banjar, Buleleng, Bali'), 'the only city of that name is a Kabupaten');
        $this->assertNull($code('Jl. Bandungan 9'), 'whole words only');
        $this->assertNull($code('Jl. Tanpa Kota 9'));
        $this->assertNull($code('  '));
        $this->assertNull($code(null));
    }

    public function test_display_name_keeps_dki_di_and_adm(): void
    {
        foreach ([
            'KOTA BANDUNG' => 'Kota Bandung',
            'KOTA ADMINISTRASI JAKARTA UTARA ' => 'Kota Administrasi Jakarta Utara',
            'DKI JAKARTA' => 'DKI Jakarta',
            'DI YOGYAKARTA' => 'DI Yogyakarta',
            'KABUPATEN ADM. KEPULAUAN SERIBU' => 'Kabupaten Adm. Kepulauan Seribu',
            'KABUPATEN TOLI-TOLI' => 'Kabupaten Toli-Toli',
            'kota surakarta' => 'Kota Surakarta',
        ] as $stored => $shown) {
            $this->assertSame($shown, IndonesiaRegionService::displayName($stored), $stored);
        }

        $this->assertNull(IndonesiaRegionService::displayName(null));
    }
}
