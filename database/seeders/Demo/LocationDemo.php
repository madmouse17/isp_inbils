<?php

namespace Database\Seeders\Demo;

use App\Models\Core\Location;
use App\Services\Core\LocationService;
use Illuminate\Support\Facades\DB;

/** Kota Bandung: region > area > POP > ODC (rack) > ODP (site), with coordinates, plus the wilayah rows addresses point at. */
final class LocationDemo
{
    private const PROVINCE = ['code' => '32', 'name' => 'JAWA BARAT'];

    private const CITY = ['code' => '3273', 'name' => 'KOTA BANDUNG'];

    /**
     * Kecamatan each POP serves, with the kelurahan an address may sit in.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const DISTRICTS = [
        'hq' => [
            'SUMUR BANDUNG' => ['BRAGA', 'KEBON PISANG', 'MERDEKA', 'BABAKAN CIAMIS'],
            'ANDIR' => ['CAMPAKA', 'CIROYOM', 'KEBONJERUK', 'MALEBER'],
            'CICENDO' => ['ARJUNA', 'PAJAJARAN', 'PASIRKALIKI', 'SUKARAJA'],
            'BANDUNG WETAN' => ['CITARUM', 'CIHAPIT', 'TAMANSARI'],
        ],
        'dgo' => [
            'COBLONG' => ['DAGO', 'LEBAK SILIWANGI', 'SEKELOA', 'CIPAGANTI', 'LEBAK GEDE'],
            'SUKAJADI' => ['SUKAWARNA', 'PASTEUR', 'CIPEDES', 'SUKABUNGAH'],
            'CIDADAP' => ['CIUMBULEUIT', 'HEGARMANAH', 'LEDENG'],
        ],
        'ant' => [
            'ANTAPANI' => ['ANTAPANI KIDUL', 'ANTAPANI TENGAH', 'ANTAPANI WETAN', 'ANTAPANI KULON'],
            'ARCAMANIK' => ['CISARANTEN KULON', 'CISARANTEN ENDAH', 'SUKAMISKIN'],
            'MANDALAJATI' => ['JATIHANDAP', 'KARANGPAMULANG', 'PASIR IMPUN'],
            'CIBEUNYING KIDUL' => ['CICADAS', 'CIKUTRA', 'PADASUKA'],
        ],
        'bbh' => [
            'BUAHBATU' => ['CIJAGRA', 'CIPAGALO', 'MARGASARI', 'SEKEJATI'],
            'LENGKONG' => ['BURANGRANG', 'MALABAR', 'TURANGGA', 'PALEDANG'],
            'KIARACONDONG' => ['BABAKAN SARI', 'CICAHEUM', 'KEBON KANGKUNG', 'SUKAPURA'],
            'REGOL' => ['ANCOL', 'BALONGGEDE', 'CIATEUL', 'PUNGKUR'],
        ],
    ];

    /** code => [name, type, registry key, parent code, lat, lng, address] */
    private const TREE = [
        'REG-JBR' => ['Jawa Barat', 'region', 'jbr', null, -6.9175, 107.6191, 'Wilayah layanan Provinsi Jawa Barat'],
        'AREA-BDG-PST' => ['Bandung Pusat', 'area', 'pst', 'REG-JBR', -6.9218, 107.6071, 'Sumur Bandung, Andir, Cicendo, Bandung Wetan'],
        'AREA-BDG-UTR' => ['Bandung Utara', 'area', 'utr', 'REG-JBR', -6.8870, 107.6130, 'Coblong, Sukajadi, Cidadap'],
        'AREA-BDG-TMR' => ['Bandung Timur', 'area', 'tmr', 'REG-JBR', -6.9150, 107.6600, 'Antapani, Arcamanik, Mandalajati, Cibeunying Kidul'],
        'AREA-BDG-SLT' => ['Bandung Selatan', 'area', 'slt', 'REG-JBR', -6.9580, 107.6340, 'Buahbatu, Lengkong, Kiaracondong, Regol'],
        'POP-BDG-HQ' => ['POP Pusat - Kantor & Gudang Utama', 'pop', 'hq', 'AREA-BDG-PST', -6.9215, 107.6075, 'Jl. Asia Afrika No. 112, Braga, Sumur Bandung, Kota Bandung 40111'],
        'POP-BDG-DGO' => ['POP Dago', 'pop', 'dgo', 'AREA-BDG-UTR', -6.8860, 107.6125, 'Jl. Ir. H. Juanda No. 255, Dago, Coblong, Kota Bandung 40135'],
        'POP-BDG-ANT' => ['POP Antapani', 'pop', 'ant', 'AREA-BDG-TMR', -6.9140, 107.6585, 'Jl. Purwakarta No. 48, Antapani Tengah, Antapani, Kota Bandung 40291'],
        'POP-BDG-BBH' => ['POP Buah Batu', 'pop', 'bbh', 'AREA-BDG-SLT', -6.9570, 107.6350, 'Jl. Buah Batu No. 301, Cijagra, Lengkong, Kota Bandung 40265'],
    ];

    /** POP key => [label used in rack and ODP names, code suffix] */
    private const POPS = [
        'dgo' => ['Dago', 'DGO'],
        'ant' => ['Antapani', 'ANT'],
        'bbh' => ['Buah Batu', 'BBH'],
    ];

    public static function seed(DemoContext $c): void
    {
        $ids = [];

        foreach (self::TREE as $code => [$name, $type, $key, $parent, $lat, $lng, $address]) {
            $location = LocationService::create([
                'parent_id' => $parent === null ? null : $ids[$parent],
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'address' => $address,
                'lat' => $lat,
                'lng' => $lng,
                'is_active' => true,
            ]);

            $ids[$code] = $location->id;
            $c->put('location', $key, $location->id);
        }

        foreach (self::POPS as $pop => [$label, $suffix]) {
            $popLocation = Location::query()->findOrFail($ids['POP-BDG-'.$suffix]);

            foreach ([1, 2] as $odc) {
                $rack = LocationService::create([
                    'parent_id' => $popLocation->id,
                    'code' => sprintf('ODC-%s-%02d', $suffix, $odc),
                    'name' => sprintf('ODC %s %02d', $label, $odc),
                    'type' => 'rack',
                    'address' => "Kabinet distribusi fiber {$label} {$odc}",
                    'lat' => round((float) $popLocation->lat + ($odc === 1 ? 0.0042 : -0.0038), 7),
                    'lng' => round((float) $popLocation->lng + ($odc === 1 ? -0.0036 : 0.0045), 7),
                    'is_active' => true,
                ]);
                $c->put('location', "{$pop}-odc{$odc}", $rack->id);

                foreach ([1, 2, 3] as $odp) {
                    $site = LocationService::create([
                        'parent_id' => $rack->id,
                        'code' => sprintf('ODP-%s-%02d-%02d', $suffix, $odc, $odp),
                        'name' => sprintf('ODP %s %02d-%02d', $label, $odc, $odp),
                        'type' => 'site',
                        'address' => "Tiang ODP {$odp}, jalur ODC {$label} {$odc}",
                        'lat' => round((float) $rack->lat + ($odp - 2) * 0.0013, 7),
                        'lng' => round((float) $rack->lng + ($odp % 2 === 0 ? 0.0011 : -0.0009), 7),
                        'is_active' => true,
                    ]);
                    $c->put('location', "{$pop}-odp{$odc}{$odp}", $site->id);
                }
            }
        }
    }

    /**
     * The kecamatan a POP serves.
     *
     * @return list<string>
     */
    public static function districts(string $pop): array
    {
        return array_keys(self::DISTRICTS[$pop]);
    }

    /**
     * Wilayah codes for an address in $district. Reuses the rows of an imported laravolt dataset when
     * it has them; otherwise adds the district (and a kelurahan) so the foreign keys of the address hold.
     *
     * @return array{province_code: string, city_code: string, district_code: string, village_code: string, village: string}
     */
    public static function region(DemoContext $c, string $pop, string $district): array
    {
        $cacheKey = $pop.'|'.$district;

        if (! $c->has('region', $cacheKey)) {
            $now = now();
            DB::table('indonesia_provinces')->insertOrIgnore([...self::PROVINCE, 'meta' => null, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('indonesia_cities')->insertOrIgnore([...self::CITY, 'province_code' => self::PROVINCE['code'], 'meta' => null, 'created_at' => $now, 'updated_at' => $now]);

            $row = DB::table('indonesia_districts')
                ->where('city_code', self::CITY['code'])
                ->whereRaw("REPLACE(UPPER(name), ' ', '') = ?", [str_replace(' ', '', $district)])
                ->first();

            if ($row === null) {
                // Seven digits never collide with the six-digit codes of the official dataset.
                $row = (object) ['code' => self::CITY['code'].sprintf('%03d', 901 + count($c->all('region'))), 'name' => $district];
                DB::table('indonesia_districts')->insertOrIgnore(['code' => $row->code, 'city_code' => self::CITY['code'], 'name' => $district, 'meta' => null, 'created_at' => $now, 'updated_at' => $now]);
            }

            $wanted = array_map(fn (string $name) => str_replace(' ', '', $name), self::DISTRICTS[$pop][$district]);
            $village = DB::table('indonesia_villages')
                ->where('district_code', $row->code)
                ->whereIn(DB::raw("REPLACE(UPPER(name), ' ', '')"), $wanted)
                ->orderBy('code')
                ->first()
                ?? DB::table('indonesia_villages')->where('district_code', $row->code)->orderBy('code')->first();

            if ($village === null) {
                $village = (object) ['code' => $row->code.'001', 'name' => self::DISTRICTS[$pop][$district][0]];
                DB::table('indonesia_villages')->insertOrIgnore(['code' => $village->code, 'district_code' => $row->code, 'name' => $village->name, 'meta' => null, 'created_at' => $now, 'updated_at' => $now]);
            }

            $c->put('region', $cacheKey, [
                'province_code' => self::PROVINCE['code'],
                'city_code' => self::CITY['code'],
                'district_code' => $row->code,
                'village_code' => $village->code,
                'village' => (string) $village->name,
            ]);
        }

        return $c->get('region', $cacheKey);
    }
}
