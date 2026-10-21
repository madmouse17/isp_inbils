import type { FormEvent } from 'react';
import { useEffect, useMemo, useState } from 'react';
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
import { SuggestionChip } from '@/Components/composite/SuggestionChip';
import { usePermission } from '@/hooks/usePermission';
import { LatLngMapPicker } from '@/Components/composite/LatLngMapPicker';
import { useMapDefault } from '@/hooks/useMapDefault';
import { formatNumber } from '@/lib/format';
import type { PageProps } from '@/types';
import type { CompanyWhatsApp } from '@/types/whatsapp';

type DayKey = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';

/** The open hours of the company, `[opens, closes]` as `HH:mm`; a closed day is null. */
type ServiceWeek = Record<DayKey, [string, string] | null>;

type SettingValue = string | number | boolean | string[] | null;

const SERVICE_DAYS: { key: DayKey; label: string }[] = [
    { key: 'mon', label: 'Senin' },
    { key: 'tue', label: 'Selasa' },
    { key: 'wed', label: 'Rabu' },
    { key: 'thu', label: 'Kamis' },
    { key: 'fri', label: 'Jumat' },
    { key: 'sat', label: 'Sabtu' },
    { key: 'sun', label: 'Minggu' },
];
// What a day shows when it is opened from "Tutup".
const DEFAULT_OPENS = '08:00';
const DEFAULT_CLOSES = '17:00';

interface CityOption {
    code: string;
    name: string;
    province_name: string;
    lat: number;
    lng: number;
}

interface SettingsProps {
    settings: Record<string, SettingValue>;
    /** The open hours of the company, all seven days; a closed day or a week never set is null. */
    serviceHours: ServiceWeek;
    /** Row version of the company; sent back as expected_updated_at so a save never overwrites a newer one. */
    version: string;
    whatsapp: CompanyWhatsApp;
    cities: CityOption[];
    /** The city the profile address names, offered as a one-click choice while no region is picked. */
    suggestedCity: { code: string; name: string } | null;
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

const SETTING_SECTIONS: { id: string; title: string; fields: Record<string, SettingField> }[] = [
    {
        id: 'tagihan',
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
            auto_send_installation_invoice: {
                label: 'Kirim tagihan pemasangan otomatis',
                kind: 'switch',
                help: 'Mati: tagihan pemasangan dibuat sebagai draf saat SPK pemasangan disetujui, lalu staf penagihan diberi tahu untuk mengirimnya. Nyala: tagihan langsung terkirim ke pelanggan.',
            },
            bill_installation_materials: {
                label: 'Tagihkan bahan terpakai',
                kind: 'switch',
                help: 'Mati: bahan yang dipakai teknisi termasuk biaya pemasangan. Nyala: bahan ditagihkan per baris dengan label Bahan terpakai.',
            },
        },
    },
    {
        id: 'isolir',
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
        id: 'lapangan',
        title: 'Lapangan',
        fields: {
            checkin_max_distance_m: {
                label: 'Jarak check-in maksimal',
                kind: 'number',
                unit: 'meter',
                min: 50,
                max: 100000,
                step: 1,
                help: 'Teknisi yang memulai pekerjaan lebih jauh dari ini dari lokasi pelanggan wajib menulis alasan. Check-in ditandai di halaman tiket dan SPK.',
            },
        },
    },
    {
        id: 'format',
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
// Section cards are the targets of the section links: below lg a sticky bar of about 56px sits over the top edge.
const SECTION_ANCHOR = 'scroll-mt-16 lg:scroll-mt-4';
// What "Pakai saran" fills in: a first invoice for every new customer, and a suspension one week after the due date.
const BILLING_SUGGESTION: Record<string, SettingValue> = {
    auto_send_installation_invoice: true,
    auto_suspend_enabled: true,
    auto_suspend_overdue_days: 7,
};
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

export default function Settings({
    settings,
    serviceHours,
    version,
    whatsapp,
    cities,
    suggestedCity,
    can,
}: SettingsProps) {
    const { data, setData, put, transform, processing, errors } = useForm({
        settings: withRoundedPoint(settings),
        service_hours: serviceHours,
        expected_updated_at: version,
    });
    // What the form held at the last save, for the unsaved marker. Inertia's own isDirty also counts expected_updated_at,
    // which the effect below refreshes after every save, so it would report changes that nobody made.
    // shortcut: compares JSON text, so a key written in another order would read as a change; every key the form edits is
    // already in the page props, so none is ever added in a new place.
    const [savedSettings, setSavedSettings] = useState(() =>
        JSON.stringify([data.settings, data.service_hours]),
    );
    const dirty = JSON.stringify([data.settings, data.service_hours]) !== savedSettings;
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

            return {
                settings: next,
                service_hours: form.service_hours,
                expected_updated_at: form.expected_updated_at,
            };
        });
        put(route('admin.company.settings.update'), {
            onSuccess: () => setSavedSettings(JSON.stringify([data.settings, data.service_hours])),
        });
    };

    const setSetting = (key: string, value: SettingValue) =>
        setData('settings', { ...data.settings, [key]: value });

    // A week without an open day is "not set".
    const week = data.service_hours;
    const serviceHoursSet = SERVICE_DAYS.some(({ key }) => week[key] !== null);
    const setDay = (day: DayKey, hours: [string, string] | null) =>
        setData('service_hours', { ...week, [day]: hours });

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

    const shownSections = SETTING_SECTIONS.filter((section) =>
        Object.keys(section.fields).some((key) => key in data.settings),
    );
    // One link per card below, so no link points at a section that is not on the page.
    const navSections = [
        ...shownSections.map(({ id, title }) => ({ id, label: title })),
        { id: 'peta', label: 'Peta' },
        { id: 'jam-layanan', label: 'Jam layanan' },
        { id: 'whatsapp', label: 'WhatsApp' },
        { id: 'keamanan', label: 'Keamanan' },
    ];
    const sectionLinks = (variant: 'outline' | 'ghost') =>
        navSections.map((section) => (
            <li key={section.id} className="shrink-0">
                <Button
                    asChild
                    size="sm"
                    variant={variant}
                    className={variant === 'ghost' ? 'w-full justify-start' : undefined}
                >
                    <a href={`#${section.id}`}>{section.label}</a>
                </Button>
            </li>
        ));
    // The callout asks once: the server stamps the first save, and the page then comes back without it.
    const showBillingCallout = can.update && !settings.billing_defaults_confirmed_at;
    const suggestionApplied = Object.entries(BILLING_SUGGESTION).every(
        ([key, value]) => String(data.settings[key]) === String(value),
    );

    return (
        <AdminLayout title="Pengaturan Perusahaan">
            <div className="space-y-6">
                <PageHeader
                    title="Pengaturan Perusahaan"
                    subtitle="Aturan tagihan, peta bawaan, WhatsApp, dan keamanan untuk perusahaan ini."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={route('admin.company.profile.edit')}>
                                Profil perusahaan
                            </Link>
                        </Button>
                    }
                />
                <ConflictBanner />

                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:gap-8">
                    <nav
                        aria-label="Bagian pengaturan"
                        className="sticky top-0 z-10 -mx-4 border-b bg-background px-4 py-2 md:-mx-6 md:px-6 lg:top-4 lg:mx-0 lg:w-52 lg:shrink-0 lg:border-b-0 lg:bg-transparent lg:p-0"
                    >
                        <ul className="flex gap-2 overflow-x-auto p-0.5 lg:hidden">
                            {sectionLinks('outline')}
                        </ul>
                        <ul className="hidden flex-col gap-1 lg:flex">{sectionLinks('ghost')}</ul>
                    </nav>

                    {/* noValidate: the server refuses a value with its own Indonesian message on the field; min and max stay as hints of the number input. */}
                    <form onSubmit={submit} noValidate className="space-y-6 min-w-0 flex-1">
                        {shownSections.map((section) => (
                            <Card key={section.id} id={section.id} className={SECTION_ANCHOR}>
                                <CardHeader>
                                    <CardTitle>{section.title}</CardTitle>
                                </CardHeader>
                                <CardContent className="grid gap-x-4 gap-y-5 md:grid-cols-2">
                                    {section.id === 'tagihan' && showBillingCallout && (
                                        <Alert
                                            variant="info"
                                            role="status"
                                            className="md:col-span-2"
                                        >
                                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                                <AlertDescription>
                                                    Pilih cara penagihan otomatis. Saran kami: kirim
                                                    tagihan pemasangan otomatis, dan isolir
                                                    langganan 7 hari setelah jatuh tempo.
                                                    {suggestionApplied &&
                                                        ' Saran sudah terpasang di formulir. Tekan Simpan untuk menyimpannya.'}
                                                </AlertDescription>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    className="shrink-0"
                                                    disabled={suggestionApplied}
                                                    onClick={() =>
                                                        setData('settings', {
                                                            ...data.settings,
                                                            ...BILLING_SUGGESTION,
                                                        })
                                                    }
                                                >
                                                    Pakai saran
                                                </Button>
                                            </div>
                                        </Alert>
                                    )}
                                    {Object.entries(section.fields)
                                        .filter(([key]) => key in data.settings)
                                        .map(([key, field]) => renderField(key, field))}
                                </CardContent>
                            </Card>
                        ))}

                        <Card id="peta" className={SECTION_ANCHOR}>
                            <CardHeader>
                                <CardTitle>Peta</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <p className="text-sm text-muted-foreground">
                                    Peta pelanggan, alamat, dan lokasi dibuka di wilayah ini. Titik
                                    yang sudah tersimpan pada data tetap lebih utama. Kosongkan
                                    lintang dan bujur untuk kembali ke seluruh Indonesia.
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
                                {can.update && suggestedCity && map.cityCode === '' && (
                                    <SuggestionChip
                                        value={`${suggestedCity.name} (dari alamat profil)`}
                                        onUse={() => selectCity(suggestedCity.code)}
                                    />
                                )}
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
                                    // isolate: the map keeps its own z-index layers below the sticky bars of this page.
                                    className="isolate"
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

                        <Card id="jam-layanan" className={SECTION_ANCHOR}>
                            <CardHeader>
                                <CardTitle>Jam layanan</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <p className="text-sm text-muted-foreground">
                                    Jam buka perusahaan, menurut zona waktu perusahaan. Tingkat SLA
                                    dengan hitungan tenggat Jam layanan hanya menghitung jam di
                                    sini: tiket yang masuk di luar jam buka mulai dihitung saat buka
                                    berikutnya.
                                </p>
                                {!serviceHoursSet && (
                                    <Alert variant="warning" role="status">
                                        <AlertDescription>
                                            Jam layanan belum diisi; tenggat dihitung 24 jam.
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <div className="grid gap-3 lg:grid-cols-2">
                                    {SERVICE_DAYS.map(({ key, label }) => {
                                        const hours = week[key];
                                        const dayError = settingErrors[`service_hours.${key}`];

                                        return (
                                            <div
                                                key={key}
                                                role="group"
                                                aria-labelledby={`jam-layanan-${key}`}
                                                className="rounded-lg border p-3"
                                            >
                                                <div className="flex items-center justify-between gap-3">
                                                    <h3
                                                        id={`jam-layanan-${key}`}
                                                        className="text-sm font-medium"
                                                    >
                                                        {label}
                                                    </h3>
                                                    <Checkbox
                                                        label="Tutup"
                                                        checked={hours === null}
                                                        disabled={!can.update}
                                                        onCheckedChange={(closed) =>
                                                            setDay(
                                                                key,
                                                                closed === true
                                                                    ? null
                                                                    : [
                                                                          DEFAULT_OPENS,
                                                                          DEFAULT_CLOSES,
                                                                      ],
                                                            )
                                                        }
                                                    />
                                                </div>
                                                {hours !== null && (
                                                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                                        <TimeInput
                                                            label="Dari"
                                                            value={hours[0]}
                                                            onChange={(time) =>
                                                                setDay(key, [time, hours[1]])
                                                            }
                                                            error={
                                                                settingErrors[
                                                                    `service_hours.${key}.0`
                                                                ]
                                                            }
                                                            disabled={!can.update}
                                                        />
                                                        <TimeInput
                                                            label="Sampai"
                                                            value={hours[1]}
                                                            onChange={(time) =>
                                                                setDay(key, [hours[0], time])
                                                            }
                                                            error={
                                                                settingErrors[
                                                                    `service_hours.${key}.1`
                                                                ]
                                                            }
                                                            disabled={!can.update}
                                                        />
                                                    </div>
                                                )}
                                                {dayError && (
                                                    <p className="mt-2 text-sm text-destructive-text">
                                                        {dayError}
                                                    </p>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                                {settingErrors.service_hours && (
                                    <p className="text-sm text-destructive-text">
                                        {settingErrors.service_hours}
                                    </p>
                                )}
                                <p className="text-sm text-muted-foreground">
                                    Jam dukungan yang dibaca pelanggan di aplikasi diisi terpisah di{' '}
                                    <Link
                                        href={route('admin.company.profile.edit')}
                                        className="font-medium text-primary-text underline underline-offset-2"
                                    >
                                        Profil perusahaan
                                    </Link>
                                    . Samakan keduanya.
                                </p>
                            </CardContent>
                        </Card>

                        <Card id="whatsapp" className={SECTION_ANCHOR}>
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
                                        <p className="text-sm text-destructive-text">
                                            {eventsError}
                                        </p>
                                    )}
                                </fieldset>

                                <div className="grid gap-4 md:grid-cols-3">
                                    <TimeInput
                                        label="Jam tenang mulai"
                                        value={String(data.settings.whatsapp_quiet_start ?? '')}
                                        onChange={(time) =>
                                            setSetting('whatsapp_quiet_start', time)
                                        }
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

                        <Card id="keamanan" className={SECTION_ANCHOR}>
                            <CardHeader>
                                <CardTitle>Keamanan</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <Switch
                                    label="Wajib 2FA untuk admin"
                                    checked={data.settings.require_admin_two_factor === true}
                                    disabled={!can.update}
                                    onCheckedChange={(checked) =>
                                        setSetting('require_admin_two_factor', checked)
                                    }
                                />
                                <p className="text-sm text-muted-foreground">
                                    Admin adalah pengguna yang boleh mengelola perusahaan, pengguna,
                                    atau peran. Selama saklar ini nyala, admin yang belum
                                    mengaktifkan 2FA diarahkan ke Profil sampai 2FA aktif. Aktifkan
                                    2FA Anda sendiri lebih dulu di{' '}
                                    <Link
                                        href={route('profile.edit')}
                                        className="font-medium text-primary-text underline underline-offset-2"
                                    >
                                        Profil
                                    </Link>
                                    .
                                </p>
                                {settingErrors['settings.require_admin_two_factor'] && (
                                    <p className="text-sm text-destructive-text">
                                        {settingErrors['settings.require_admin_two_factor']}
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        {can.update && (
                            <div className="sticky bottom-0 z-10 -mx-4 flex items-center justify-between gap-3 border-t bg-background px-4 py-3 md:-mx-6 md:px-6 lg:mx-0 lg:rounded-lg lg:border">
                                <p role="status" className="text-sm font-medium text-foreground">
                                    {dirty ? 'Ada perubahan yang belum disimpan' : ''}
                                </p>
                                <Button type="submit" loading={processing}>
                                    Simpan
                                </Button>
                            </div>
                        )}
                    </form>
                </div>
            </div>
        </AdminLayout>
    );
}
