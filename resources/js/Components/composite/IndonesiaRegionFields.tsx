import { SearchSelect } from '@/Components/ui/SearchSelect';

interface ProvinceOption {
    code: string;
    name: string;
}

interface CityOption extends ProvinceOption {
    province_code: string;
}

interface DistrictOption extends ProvinceOption {
    city_code: string;
}

interface VillageOption extends ProvinceOption {
    district_code: string;
}

export interface IndonesiaRegionOptions {
    provinces: ProvinceOption[];
    cities: CityOption[];
    districts: DistrictOption[];
    villages: VillageOption[];
}

export interface IndonesiaRegionValue {
    province_code: string;
    city_code: string;
    district_code: string;
    village_code: string;
    city: string;
}

interface IndonesiaRegionFieldsProps {
    value: IndonesiaRegionValue;
    options: IndonesiaRegionOptions;
    onChange: (value: IndonesiaRegionValue) => void;
    errors?: Partial<Record<keyof IndonesiaRegionValue, string>>;
    idPrefix?: string;
}

export function IndonesiaRegionFields({
    value,
    options,
    onChange,
    errors = {},
    idPrefix = 'indonesia-region',
}: IndonesiaRegionFieldsProps) {
    const cities = options.cities.filter((city) => city.province_code === value.province_code);
    const districts = options.districts.filter(
        (district) => district.city_code === value.city_code,
    );
    const villages = options.villages.filter(
        (village) => village.district_code === value.district_code,
    );

    return (
        <div className="grid gap-4 md:col-span-2 md:grid-cols-2">
            <SearchSelect
                id={`${idPrefix}-province`}
                label="Provinsi"
                value={value.province_code}
                error={errors.province_code}
                required
                placeholder="Cari provinsi"
                onChange={(provinceCode) =>
                    onChange({
                        ...value,
                        province_code: provinceCode,
                        city_code: '',
                        district_code: '',
                        village_code: '',
                        city: '',
                    })
                }
                options={options.provinces.map((province) => ({
                    value: province.code,
                    label: province.name,
                }))}
            />
            <SearchSelect
                id={`${idPrefix}-city`}
                label="Kota / Kabupaten"
                value={value.city_code}
                error={errors.city_code}
                disabled={!value.province_code}
                required
                placeholder="Cari kota / kabupaten"
                onChange={(cityCode) => {
                    onChange({
                        ...value,
                        city_code: cityCode,
                        district_code: '',
                        village_code: '',
                        city: cities.find((city) => city.code === cityCode)?.name ?? '',
                    });
                }}
                options={cities.map((city) => ({ value: city.code, label: city.name }))}
            />
            <SearchSelect
                id={`${idPrefix}-district`}
                label="Kecamatan"
                value={value.district_code}
                error={errors.district_code}
                disabled={!value.city_code}
                required
                placeholder="Cari kecamatan"
                onChange={(districtCode) =>
                    onChange({ ...value, district_code: districtCode, village_code: '' })
                }
                options={districts.map((district) => ({
                    value: district.code,
                    label: district.name,
                }))}
            />
            <SearchSelect
                id={`${idPrefix}-village`}
                label="Kelurahan / Desa"
                value={value.village_code}
                error={errors.village_code}
                disabled={!value.district_code}
                required
                placeholder="Cari kelurahan / desa"
                onChange={(villageCode) => onChange({ ...value, village_code: villageCode })}
                options={villages.map((village) => ({ value: village.code, label: village.name }))}
            />
        </div>
    );
}
