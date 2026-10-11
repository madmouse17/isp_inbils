import type { FormEvent } from 'react';
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
    Switch,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import { formatNumber } from '@/lib/format';
import type { CompanyWhatsApp } from '@/types/whatsapp';

type SettingValue = string | number | boolean | string[];

interface SettingsProps {
    settings: Record<string, SettingValue>;
    defaults: Record<string, SettingValue | null>;
    whatsapp: CompanyWhatsApp;
    can: { update: boolean };
}

const WHATSAPP_PREFIX = 'whatsapp_';

const usageLabels: [keyof CompanyWhatsApp['usage'], string][] = [
    ['sent', 'Terkirim'],
    ['delivered', 'Diterima'],
    ['failed', 'Gagal'],
    ['billable', 'Berbayar'],
];

export default function Settings({ settings, defaults, whatsapp, can }: SettingsProps) {
    const { data, setData, put, transform, processing, errors } = useForm({
        settings: { ...settings },
    });
    // The WhatsApp keys have their own card; the generic list renders everything else.
    const keys = Object.keys(data.settings)
        .filter((key) => !key.startsWith(WHATSAPP_PREFIX))
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
        // The cap is typed as text: an emptied field must fail validation instead of becoming 0,
        // which would mean "send nothing".
        transform((form) => {
            const cap = form.settings.whatsapp_monthly_cap;

            return typeof cap === 'string' && cap !== ''
                ? { settings: { ...form.settings, whatsapp_monthly_cap: Number(cap) } }
                : form;
        });
        put(route('admin.company.settings.update'));
    };

    const setSetting = (key: string, value: SettingValue) =>
        setData('settings', { ...data.settings, [key]: value });

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
