import type { FormEvent } from 'react';
import { useEffect, useMemo } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
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
    Textarea,
    TimeInput,
} from '@/Components/ui';
import { ConflictBanner, PageHeader } from '@/Components/composite';
import { usePermission } from '@/hooks/usePermission';
import { LatLngMapPicker } from '@/Components/composite/LatLngMapPicker';
import { useMapDefault } from '@/hooks/useMapDefault';
import { formatNumber } from '@/lib/format';
import type { PageProps } from '@/types';
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
    /** Row version of the company; sent back as expected_updated_at so a save never overwrites a newer one. */
    version: string;
    whatsapp: CompanyWhatsApp;
    cities: CityOption[];
    can: { update: boolean };
}

/** How one stored setting reads on screen. A key that is not listed here is kept as it is and not shown. */
interface SettingField {
    label: string;
    kind: 'number' | 'text' | 'lines' | 'switch';
    unit?: string;
    help: string;
    min?: number;
    max?: number;
    step?: number;
}

const SETTING_SECTIONS: { title: string; fields: Record<string, SettingField> }[] = [
    {
        title: 'Tagihan',
        fields: {
            tax_ppn_rate: {
                label: 'Tarif PPN',
                kind: 'number',
                unit: '%',
                min: 0,
                max: 100,
                step: 0.01,
                help: 'Dipakai menghitung pajak pada tagihan baru.',
            },
            invoice_due_days: {
                label: 'Jatuh tempo tagihan',
                kind: 'number',
                unit: 'hari',
                min: 1,
                max: 365,
                step: 1,
                help: 'Jumlah hari dari tanggal terbit sampai jatuh tempo.',
            },
            bank_account_info: {
                label: 'Rekening pembayaran',
                kind: 'lines',
                help: 'Tampil di halaman tagihan. Tulis satu rekening per baris.',
            },
            spk_auto_invoice: {
                label: 'Buat tagihan otomatis saat SPK selesai',
                kind: 'switch',
                help: 'Tagihan dibuat begitu SPK dinyatakan selesai.',
            },
        },
    },
    {
        title: 'Penangguhan otomatis',
        fields: {
            auto_suspend_enabled: {
                label: 'Tangguhkan langganan yang menunggak',
                kind: 'switch',
                help: 'Langganan ditangguhkan saat tagihannya lewat jatuh tempo melebihi batas di bawah.',
            },
            auto_suspend_overdue_days: {
                label: 'Batas keterlambatan',
                kind: 'number',
                unit: 'hari',
                min: 1,
                max: 60,
                step: 1,
                help: 'Berapa hari setelah jatuh tempo langganan ditangguhkan.',
            },
            auto_reactivate_on_payment: {
                label: 'Aktifkan kembali saat tagihan lunas',
                kind: 'switch',
                help: 'Langganan yang ditangguhkan otomatis aktif lagi setelah tunggakannya dibayar.',
            },
        },
    },
    {
        title: 'Format tanggal',
        fields: {
            date_format: {
                label: 'Format tanggal',
                kind: 'text',
                help: 'Pola penulisan tanggal di seluruh halaman, mis. d M Y atau d/m/Y.',
            },
            datetime_format: {
                label: 'Format tanggal dan jam',
                kind: 'text',
                help: 'Pola penulisan tanggal beserta jam, mis. d M Y H:i.',
            },
        },
    },
];

// Zoom a city is framed at when picked from the list.
const CITY_ZOOM = '12';
// Typed as text while editing; an emptied field must reach the server as empty, not become 0.
const NUMERIC_TEXT_KEYS = [
    'whatsapp_monthly_cap',
    'map_lat',
    'map_lng',
    'map_zoom',
    ...SETTING_SECTIONS.flatMap(({ fields }) =>
        Object.entries(fields)
            .filter(([, field]) => field.kind === 'number')
            .map(([key]) => key),
    ),
];

const usageLabels: [keyof CompanyWhatsApp['usage'], string][] = [
    ['sent', 'Terkirim'],
    ['delivered', 'Diterima'],
    ['failed', 'Gagal'],
    ['billable', 'Berbayar'],
];

/** A coordinate with at most six decimals (about 10 cm): what the map and the city list hand over has more. */
function roundCoord(value: string | number | null | undefined): string {
    const number = Number(value);

    return value === null || value === undefined || value === '' || !Number.isFinite(number)
        ? String(value ?? '')
        : String(Number(number.toFixed(6)));
}

/** The settings as the form starts with them: the stored map point at six decimals. */
function withRoundedPoint(settings: Record<string, SettingValue>): Record<string, SettingValue> {
    const next = { ...settings };
    for (const key of ['map_lat', 'map_lng']) {
        const value = next[key];
        if (typeof value === 'number' || typeof value === 'string') next[key] = roundCoord(value);
    }

    return next;
}

export default function Settings({ settings, version, whatsapp, cities, can }: SettingsProps) {
    const { data, setData, put, transform, processing, errors } = useForm({
        settings: withRoundedPoint(settings),
        expected_updated_at: version,
    });
    const canManageIntegrations = usePermission().can('company.integrations.manage');
    const conflict = usePage<PageProps>().props.errors.conflict;
    // The save redirects back to this page, so the new version must replace the old one or the next save conflicts with the user's own. After a conflict the old version stays, so a retry conflicts until the page is reloaded.
    useEffect(() => {
        if (!conflict) setData('expected_updated_at', version);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [version]);
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

            return { settings: next, expected_updated_at: form.expected_updated_at };
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
        lat: String(data.settings.map_lat ?? roundCoord(mapDefault.center.lat)),
        lng: String(data.settings.map_lng ?? roundCoord(mapDefault.center.lng)),
        zoom: String(data.settings.map_zoom ?? mapDefault.zoom),
    };
    const zoomNumber = Number(map.zoom);
    const zoomIsValid = Number.isInteger(zoomNumber) && zoomNumber >= 3 && zoomNumber <= 18;
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
                ? {
                      cityCode: code,
                      lat: roundCoord(city.lat),
                      lng: roundCoord(city.lng),
                      zoom: CITY_ZOOM,
                  }
                : { cityCode: '' },
        );
    };

    // A point picked on the map no longer belongs to the picked city, so the city label goes.
    const setMapPoint = (point: { lat: string; lng: string }) =>
        updateMap({ lat: roundCoord(point.lat), lng: roundCoord(point.lng), cityCode: '' });

    const toggleEvent = (eventKey: string, enabled: boolean) =>
        setSetting(
            'whatsapp_events',
            whatsapp.events
                .map((event) => event.key)
                .filter((key) => (key === eventKey ? enabled : enabledEvents.includes(key))),
        );

    const renderField = (key: string, field: SettingField) => {
        const value = data.settings[key];
        const error = settingErrors[`settings.${key}`];
        const label = field.unit ? `${field.label} (${field.unit})` : field.label;

        if (field.kind === 'switch') {
            return (
                <div key={key} className="space-y-1.5 md:col-span-2">
                    <Switch
                        label={label}
                        checked={value === true}
                        disabled={!can.update}
                        onCheckedChange={(checked) => setSetting(key, checked)}
                    />
                    <p className="text-sm text-muted-foreground">{field.help}</p>
                    {error && <p className="text-sm text-destructive-text">{error}</p>}
                </div>
            );
        }

        if (field.kind === 'lines') {
            return (
                <div key={key} className="md:col-span-2">
                    <Textarea
                        className="min-h-24"
                        label={label}
                        value={String(value ?? '')}
                        onChange={(e) => setSetting(key, e.target.value)}
                        hint={field.help}
                        error={error}
                        disabled={!can.update}
                    />
                </div>
            );
        }

        return (
            <Input
                key={key}
                label={label}
                type={field.kind === 'number' ? 'number' : 'text'}
                inputMode={field.kind === 'number' ? 'decimal' : undefined}
                min={field.min}
                max={field.max}
                step={field.step}
                value={String(value ?? '')}
                onChange={(e) => setSetting(key, e.target.value)}
                hint={field.help}
                error={error}
                disabled={!can.update}
            />
        );
    };

    return (
        <AdminLayout title="Pengaturan Perusahaan">
            <div className="space-y-6">
                <PageHeader
                    title="Pengaturan Perusahaan"
                    subtitle="Aturan tagihan, peta bawaan, dan WhatsApp untuk perusahaan ini."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={route('admin.company.profile.edit')}>
                                Profil perusahaan
                            </Link>
                        </Button>
                    }
                />
                <ConflictBanner />

                <form onSubmit={submit} className="space-y-6">
                    {SETTING_SECTIONS.map((section) => {
                        const shown = Object.entries(section.fields).filter(
                            ([key]) => key in data.settings,
                        );

                        if (shown.length === 0) return null;

                        return (
                            <Card key={section.title}>
                                <CardHeader>
                                    <CardTitle>{section.title}</CardTitle>
                                </CardHeader>
                                <CardContent className="grid gap-x-4 gap-y-5 md:grid-cols-2">
                                    {shown.map(([key, field]) => renderField(key, field))}
                                </CardContent>
                            </Card>
                        );
                    })}

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
                                    label="Lintang"
                                    inputMode="decimal"
                                    value={map.lat}
                                    onChange={(e) =>
                                        updateMap({ lat: e.target.value, cityCode: '' })
                                    }
                                    error={settingErrors['settings.map_lat']}
                                    disabled={!can.update}
                                />
                                <Input
                                    label="Bujur"
                                    inputMode="decimal"
                                    value={map.lng}
                                    onChange={(e) =>
                                        updateMap({ lng: e.target.value, cityCode: '' })
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
                                        WhatsApp belum dikonfigurasi untuk perusahaan ini.{' '}
                                        {canManageIntegrations ? (
                                            <Link
                                                href={route('admin.company.integrations.edit')}
                                                className="font-medium underline underline-offset-2"
                                            >
                                                Atur di Pengaturan → Integrasi
                                            </Link>
                                        ) : (
                                            'Minta admin mengisinya di Pengaturan → Integrasi.'
                                        )}
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
                                <p className="text-sm text-destructive-text">
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
                                    <p className="text-sm text-destructive-text">{eventsError}</p>
                                )}
                            </fieldset>

                            <div className="grid gap-4 md:grid-cols-3">
                                <TimeInput
                                    label="Jam tenang mulai"
                                    value={String(data.settings.whatsapp_quiet_start ?? '')}
                                    onChange={(time) => setSetting('whatsapp_quiet_start', time)}
                                    hint="Tagihan terbit dan pengingat ditunda sampai jam tenang selesai, menurut zona waktu perusahaan."
                                    error={settingErrors['settings.whatsapp_quiet_start']}
                                    disabled={!can.update}
                                />
                                <TimeInput
                                    label="Jam tenang selesai"
                                    value={String(data.settings.whatsapp_quiet_end ?? '')}
                                    onChange={(time) => setSetting('whatsapp_quiet_end', time)}
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
                                <h3 id="whatsapp-usage" className="text-sm font-medium">
                                    Pemakaian bulan ini
                                </h3>
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
                                Simpan
                            </Button>
                        </div>
                    )}
                </form>
            </div>
        </AdminLayout>
    );
}
