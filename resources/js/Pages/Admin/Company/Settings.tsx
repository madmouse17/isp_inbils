import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Alert,
    AlertDescription,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Checkbox,
    Input,
    SearchSelect,
    Switch,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import { LatLngMapPicker } from '@/Components/composite/LatLngMapPicker';
import { useMapDefault } from '@/hooks/useMapDefault';
import { formatNumber } from '@/lib/format';
import type { CompanyWhatsApp } from '@/types/whatsapp';

type SettingValue = string | number | boolean | string[] | null;

interface CityOption {
    code: string;
    name: string;
    province_name: string;
    lat: number;
    lng: number;
}

interface SettingsProps {
    settings: Record<string, SettingValue>;
    defaults: Record<string, SettingValue>;
    whatsapp: CompanyWhatsApp;
    cities: CityOption[];
    can: { update: boolean };
}

const WHATSAPP_PREFIX = 'whatsapp_';
const MAP_PREFIX = 'map_';
// Zoom a city is framed at when picked from the list.
const CITY_ZOOM = '12';
// Typed as text while editing; an emptied field must reach the server as empty, not become 0.
const NUMERIC_TEXT_KEYS = ['whatsapp_monthly_cap', 'map_lat', 'map_lng', 'map_zoom'];

const usageLabels: [keyof CompanyWhatsApp['usage'], string][] = [
    ['sent', 'Terkirim'],
    ['delivered', 'Diterima'],
    ['failed', 'Gagal'],
    ['billable', 'Berbayar'],
];

export default function Settings({ settings, defaults, whatsapp, cities, can }: SettingsProps) {
    const { data, setData, put, transform, processing, errors } = useForm({
        settings: { ...settings },
    });
    // The WhatsApp and map keys have their own cards; the generic list renders everything else.
    const keys = Object.keys(data.settings)
        .filter((key) => !key.startsWith(WHATSAPP_PREFIX) && !key.startsWith(MAP_PREFIX))
        .sort();
    const settingErrors = errors as Record<string, string | undefined>;
    const eventsError = Object.entries(settingErrors).find(([key]) =>
        key.startsWith('settings.whatsapp_events'),
    )?.[1];
    const enabledEvents = Array.isArray(data.settings.whatsapp_events)
        ? data.settings.whatsapp_events
        : [];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        transform((form) => {
            const next = { ...form.settings };
            for (const key of NUMERIC_TEXT_KEYS) {
                const value = next[key];
                if (typeof value === 'string' && value !== '') {
                    next[key] = Number(value);
                }
            }

            return { settings: next };
        });
        put(route('admin.company.settings.update'));
    };

    const setSetting = (key: string, value: SettingValue) =>
        setData('settings', { ...data.settings, [key]: value });

    // Keys the company never set show what the maps resolve to today.
    const mapDefault = useMapDefault();
    const map = {
        cityCode:
            typeof data.settings.map_city_code === 'string' ? data.settings.map_city_code : '',
        lat: String(data.settings.map_lat ?? mapDefault.center.lat),
        lng: String(data.settings.map_lng ?? mapDefault.center.lng),
        zoom: String(data.settings.map_zoom ?? mapDefault.zoom),
    };
    const zoomNumber = Number(map.zoom);
    const zoomIsValid = Number.isInteger(zoomNumber) && zoomNumber >= 3 && zoomNumber <= 18;
    const [citySelectKey, setCitySelectKey] = useState(0);
    const cityOptions = useMemo(
        () =>
            cities.map((city) => ({
                value: city.code,
                label: city.name,
                description: city.province_name,
            })),
        [cities],
    );

    const updateMap = (next: Partial<typeof map>) => {
        const merged = { ...map, ...next };
        setData('settings', {
            ...data.settings,
            map_city_code: merged.cityCode || null,
            map_lat: merged.lat,
            map_lng: merged.lng,
            map_zoom: merged.zoom,
        });
    };

    const selectCity = (code: string) => {
        const city = cities.find((option) => option.code === code);
        // Typing in the search box also reports '' while editing: keep the point, drop the label.
        updateMap(
            city
                ? { cityCode: code, lat: String(city.lat), lng: String(city.lng), zoom: CITY_ZOOM }
                : { cityCode: '' },
        );
    };

    // A hand-set point no longer belongs to the picked city, so the city label goes.
    const setMapPoint = (point: { lat: string; lng: string }) => {
        if (map.cityCode) {
            setCitySelectKey((key) => key + 1);
        }
        updateMap({ ...point, cityCode: '' });
    };

    const toggleEvent = (eventKey: string, enabled: boolean) =>
        setSetting(
            'whatsapp_events',
            whatsapp.events
                .map((event) => event.key)
                .filter((key) => (key === eventKey ? enabled : enabledEvents.includes(key))),
        );

    return (
        <AdminLayout title="Company Settings">
            <div className="space-y-6">
                <PageHeader
                    title="Company Settings"
                    subtitle={`Company-specific config. Defaults: ${Object.keys(defaults).join(', ')}`}
                    actions={
                        <Link
                            href={route('admin.company.profile.edit')}
                            className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                        >
                            Profile
                        </Link>
                    }
                />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Settings JSON</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            {keys.map((key) => {
                                const value = data.settings[key];
                                if (typeof value === 'boolean') {
                                    return (
                                        <Switch
                                            key={key}
                                            label={key}
                                            checked={value}
                                            disabled={!can.update}
                                            onCheckedChange={(checked) => setSetting(key, checked)}
                                        />
                                    );
                                }

                                return (
                                    <Input
                                        key={key}
                                        label={key}
                                        type={typeof value === 'number' ? 'number' : 'text'}
                                        value={String(value ?? '')}
                                        onChange={(e) =>
                                            setSetting(
                                                key,
                                                typeof value === 'number'
                                                    ? Number(e.target.value)
                                                    : e.target.value,
                                            )
                                        }
                                        error={settingErrors[`settings.${key}`]}
                                        disabled={!can.update}
                                    />
                                );
                            })}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Peta</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <p className="text-sm text-muted-foreground">
                                Peta pelanggan, alamat, dan lokasi dibuka di wilayah ini. Titik yang
                                sudah tersimpan pada data tetap lebih utama. Kosongkan lintang dan
                                bujur untuk kembali ke seluruh Indonesia.
                            </p>
                            <SearchSelect
                                key={citySelectKey}
                                id="map-city"
                                label="Wilayah default"
                                value={map.cityCode}
                                onChange={selectCity}
                                options={cityOptions}
                                placeholder="Cari kota atau kabupaten"
                                emptyText="Wilayah tidak ditemukan."
                                hint="Memilih wilayah mengisi lintang, bujur, dan zoom."
                                error={settingErrors['settings.map_city_code']}
                                disabled={!can.update}
                            />
                            <div className="grid gap-4 md:grid-cols-3">
                                <Input
                                    label="Lintang (latitude)"
                                    inputMode="decimal"
                                    value={map.lat}
                                    onChange={(e) =>
                                        setMapPoint({ lat: e.target.value, lng: map.lng })
                                    }
                                    error={settingErrors['settings.map_lat']}
                                    disabled={!can.update}
                                />
                                <Input
                                    label="Bujur (longitude)"
                                    inputMode="decimal"
                                    value={map.lng}
                                    onChange={(e) =>
                                        setMapPoint({ lat: map.lat, lng: e.target.value })
                                    }
                                    error={settingErrors['settings.map_lng']}
                                    disabled={!can.update}
                                />
                                <Input
                                    label="Zoom"
                                    type="number"
                                    min={3}
                                    max={18}
                                    step={1}
                                    value={map.zoom}
                                    onChange={(e) => updateMap({ zoom: e.target.value })}
                                    hint="3 untuk seluruh Indonesia, 12 untuk satu kota."
                                    error={settingErrors['settings.map_zoom']}
                                    disabled={!can.update}
                                />
                            </div>
                            <LatLngMapPicker
                                lat={map.lat}
                                lng={map.lng}
                                onChange={setMapPoint}
                                defaultCenter={mapDefault.center}
                                zoom={zoomIsValid ? zoomNumber : undefined}
                                onZoomChange={(zoom) => {
                                    if (String(zoom) !== map.zoom) {
                                        updateMap({ zoom: String(zoom) });
                                    }
                                }}
                                heightClassName="h-80"
                                disabled={!can.update}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>WhatsApp</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {!whatsapp.configured && (
                                <Alert variant="warning">
                                    <AlertDescription>
                                        Notifikasi tidak akan terkirim sampai kredensial diatur di
                                        server.
                                    </AlertDescription>
                                </Alert>
                            )}
                            <p className="text-sm">
                                <span className="text-muted-foreground">
                                    Kredensial terkonfigurasi:{' '}
                                </span>
                                <span className="font-medium">
                                    {whatsapp.configured ? 'Ya' : 'Tidak'}
                                </span>
                            </p>
                            <Switch
                                label="Aktifkan notifikasi WhatsApp"
                                checked={data.settings.whatsapp_enabled === true}
                                disabled={!can.update}
                                onCheckedChange={(checked) =>
                                    setSetting('whatsapp_enabled', checked)
                                }
                            />
                            {settingErrors['settings.whatsapp_enabled'] && (
                                <p className="text-sm text-red-700 dark:text-red-400">
                                    {settingErrors['settings.whatsapp_enabled']}
                                </p>
                            )}

                            <fieldset className="space-y-3 min-w-0">
                                <legend className="mb-3 text-sm font-medium">
                                    Jenis pesan yang dikirim
                                </legend>
                                <div className="grid gap-3 md:grid-cols-2">
                                    {whatsapp.events.map((event) => (
                                        <Checkbox
                                            key={event.key}
                                            label={event.label}
                                            description={
                                                event.audience === 'technician'
                                                    ? 'Untuk teknisi'
                                                    : 'Untuk pelanggan'
                                            }
                                            checked={enabledEvents.includes(event.key)}
                                            disabled={!can.update}
                                            onCheckedChange={(checked) =>
                                                toggleEvent(event.key, checked === true)
                                            }
                                        />
                                    ))}
                                </div>
                                {eventsError && (
                                    <p className="text-sm text-red-700 dark:text-red-400">
                                        {eventsError}
                                    </p>
                                )}
                            </fieldset>

                            <div className="grid gap-4 md:grid-cols-3">
                                <Input
                                    label="Jam tenang mulai"
                                    type="time"
                                    value={String(data.settings.whatsapp_quiet_start ?? '')}
                                    onChange={(e) =>
                                        setSetting('whatsapp_quiet_start', e.target.value)
                                    }
                                    hint="Tagihan terbit dan pengingat ditunda sampai jam tenang selesai, menurut zona waktu perusahaan."
                                    error={settingErrors['settings.whatsapp_quiet_start']}
                                    disabled={!can.update}
                                />
                                <Input
                                    label="Jam tenang selesai"
                                    type="time"
                                    value={String(data.settings.whatsapp_quiet_end ?? '')}
                                    onChange={(e) =>
                                        setSetting('whatsapp_quiet_end', e.target.value)
                                    }
                                    error={settingErrors['settings.whatsapp_quiet_end']}
                                    disabled={!can.update}
                                />
                                <Input
                                    label="Batas pesan per bulan"
                                    type="number"
                                    min={0}
                                    max={100000}
                                    step={1}
                                    value={String(data.settings.whatsapp_monthly_cap ?? '')}
                                    onChange={(e) =>
                                        setSetting('whatsapp_monthly_cap', e.target.value)
                                    }
                                    hint="Pesan di atas batas dilewati. Isi 0 untuk tidak mengirim apa pun."
                                    error={settingErrors['settings.whatsapp_monthly_cap']}
                                    disabled={!can.update}
                                />
                            </div>

                            <section aria-labelledby="whatsapp-usage" className="space-y-3">
                                <h4 id="whatsapp-usage" className="text-sm font-medium">
                                    Pemakaian bulan ini
                                </h4>
                                <dl className="grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
                                    {usageLabels.map(([key, label]) => (
                                        <div key={key}>
                                            <dt className="text-muted-foreground">{label}</dt>
                                            <dd className="text-lg font-semibold">
                                                {formatNumber(whatsapp.usage[key])}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </section>
                        </CardContent>
                    </Card>

                    {can.update && (
                        <div className="flex justify-end">
                            <Button type="submit" loading={processing}>
                                Save Settings
                            </Button>
                        </div>
                    )}
                </form>
            </div>
        </AdminLayout>
    );
}
