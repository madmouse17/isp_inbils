import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { Link, router, useForm, usePage, usePoll } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Alert,
    AlertDescription,
    Button,
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
    EnumBadge,
    Input,
    Modal,
    NativeSelect,
    Spinner,
    Switch,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import { CopyButton } from '@/Components/composite/CopyButton';
import { useCompany } from '@/hooks/useCompany';
import { formatDateTime } from '@/lib/format';
import { INTEGRATION_PROVIDER, INTEGRATION_STATE } from '@/lib/statuses';
import type { PageProps } from '@/types';
import type {
    IntegrationCard,
    IntegrationProvider,
    IntegrationsPageProps,
} from '@/types/integrations';

type FormValues = Record<string, string | boolean>;

interface FieldSpec {
    key: string;
    label: string;
    kind: 'text' | 'secret' | 'select';
    options?: { value: string; label: string }[];
    /** Shown when the row holds no value yet. */
    fallback?: string;
    numeric?: boolean;
}

interface WebhookSpec {
    label: string;
    url: string;
    note: string;
}

const PROVIDER_ORDER: IntegrationProvider[] = ['midtrans', 'whatsapp', 'mail', 'radius'];

const PROVIDER_DESCRIPTION: Record<IntegrationProvider, string> = {
    midtrans: 'Pembayaran tagihan online masuk ke akun Midtrans perusahaan ini.',
    whatsapp: 'Notifikasi WhatsApp dikirim dari nomor milik perusahaan ini.',
    mail: 'Email ke pelanggan dan staf dikirim dari server SMTP perusahaan ini.',
    radius: 'Akun jaringan pelanggan ditulis ke database FreeRADIUS perusahaan ini.',
};

const FIELDS: Record<IntegrationProvider, FieldSpec[]> = {
    midtrans: [
        { key: 'server_key', label: 'Server key', kind: 'secret' },
        {
            key: 'is_production',
            label: 'Mode',
            kind: 'select',
            fallback: 'sandbox',
            options: [
                { value: 'sandbox', label: 'Sandbox' },
                { value: 'production', label: 'Produksi' },
            ],
        },
    ],
    whatsapp: [
        {
            key: 'phone_number_id',
            label: 'ID nomor (phone_number_id)',
            kind: 'text',
            numeric: true,
        },
        { key: 'access_token', label: 'Access token', kind: 'secret' },
        { key: 'app_secret', label: 'App secret', kind: 'secret' },
        { key: 'webhook_verify_token', label: 'Verify token webhook', kind: 'secret' },
    ],
    mail: [
        { key: 'host', label: 'Host SMTP', kind: 'text' },
        { key: 'port', label: 'Port', kind: 'text', numeric: true },
        {
            key: 'scheme',
            label: 'Enkripsi',
            kind: 'select',
            fallback: 'smtp',
            options: [
                { value: 'smtp', label: 'STARTTLS' },
                { value: 'smtps', label: 'TLS' },
            ],
        },
        { key: 'username', label: 'Nama pengguna', kind: 'text' },
        { key: 'password', label: 'Kata sandi', kind: 'secret' },
        { key: 'from_address', label: 'Alamat pengirim', kind: 'text' },
        { key: 'from_name', label: 'Nama pengirim', kind: 'text' },
    ],
    radius: [
        { key: 'host', label: 'Host', kind: 'text' },
        { key: 'port', label: 'Port', kind: 'text', numeric: true },
        { key: 'database', label: 'Database', kind: 'text' },
        { key: 'username', label: 'Nama pengguna', kind: 'text' },
        { key: 'password', label: 'Kata sandi', kind: 'secret' },
    ],
};

// The server keeps one running check per provider for 60 s; past 30 s of polling the page stops asking.
const POLL_MS = 2000;
const POLL_GIVE_UP_MS = 30_000;

function initialValues(card: IntegrationCard, fields: FieldSpec[]): FormValues {
    const values: FormValues = { is_enabled: card.is_enabled, code: '' };

    for (const field of fields) {
        if (field.kind === 'secret') {
            values[field.key] = '';
        } else if (field.key === 'is_production') {
            values[field.key] = card.settings.is_production ? 'production' : 'sandbox';
        } else {
            values[field.key] = String(card.settings[field.key] ?? field.fallback ?? '');
        }
    }

    return values;
}

/** The form holds Mode as a word; the server wants the flag. */
function toPayload(values: FormValues) {
    const { is_production: mode, ...rest } = values;

    return mode === undefined ? rest : { ...rest, is_production: mode === 'production' };
}

function StatusLine({ card }: { card: IntegrationCard }) {
    const company = useCompany();
    const when = card.last_checked_at ? formatDateTime(card.last_checked_at, company) : null;

    return (
        <div
            aria-live="polite"
            className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground"
        >
            <EnumBadge map={INTEGRATION_STATE} value={card.state} />
            {card.checking ? (
                <span className="inline-flex items-center gap-2">
                    <Spinner size="sm" />
                    Menguji…
                </span>
            ) : card.state === 'empty' ? (
                <span>Belum dikonfigurasi</span>
            ) : (
                <span>{when ? `Terakhir diuji ${when}` : 'Belum pernah diuji'}</span>
            )}
        </div>
    );
}

interface IntegrationFormCardProps {
    card: IntegrationCard;
    twoFactorEnabled: boolean;
    webhook?: WebhookSpec;
    mailFallback?: string | null;
}

function IntegrationFormCard({
    card,
    twoFactorEnabled,
    webhook,
    mailFallback,
}: IntegrationFormCardProps) {
    const { provider } = card;
    const fields = FIELDS[provider];
    const label = INTEGRATION_PROVIDER[provider].label;
    const form = useForm<FormValues>(initialValues(card, fields));
    const [confirming, setConfirming] = useState(false);
    const [testing, setTesting] = useState(false);
    const [testError, setTestError] = useState<string | null>(null);
    const idOf = (key: string) => `${provider}-${key}`;
    const codeError = form.errors.code;
    const running = card.checking || testing;

    const clearSecrets = () => {
        form.setData((current) => ({
            ...current,
            ...Object.fromEntries(
                fields.filter((field) => field.kind === 'secret').map((field) => [field.key, '']),
            ),
            code: '',
        }));
    };

    const save = (event: FormEvent) => {
        event.preventDefault();
        // The modal is portalled out of the card's <form>, but React still bubbles its submit to it.
        event.stopPropagation();
        form.transform(toPayload);
        form.put(route('admin.company.integrations.update', provider), {
            preserveScroll: true,
            onSuccess: () => {
                clearSecrets();
                setConfirming(false);
            },
            onError: (errors) => {
                // The code is consumed by a failed attempt too, so the next one needs a fresh one.
                form.setData('code', '');
                if (!errors.code) setConfirming(false);
            },
        });
    };

    const test = () => {
        setTestError(null);
        router.post(
            route('admin.company.integrations.test', provider),
            {},
            {
                preserveScroll: true,
                onStart: () => setTesting(true),
                onFinish: () => setTesting(false),
                onError: (errors) => setTestError(errors.provider ?? null),
            },
        );
    };

    return (
        <Card role="region" aria-labelledby={idOf('title')}>
            <CardHeader>
                <CardTitle id={idOf('title')}>{label}</CardTitle>
                <CardDescription>{PROVIDER_DESCRIPTION[provider]}</CardDescription>
                <StatusLine card={card} />
            </CardHeader>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    setConfirming(true);
                }}
            >
                <CardContent className="space-y-5">
                    {card.last_error && (
                        <Alert variant="danger">
                            <AlertDescription>{card.last_error}</AlertDescription>
                        </Alert>
                    )}
                    {provider === 'mail' && !card.is_enabled && (
                        <p className="text-sm text-muted-foreground">
                            {mailFallback
                                ? `Selama nonaktif, email dikirim dari pengirim platform ${mailFallback}.`
                                : 'Selama nonaktif, email dikirim dari pengirim platform.'}
                        </p>
                    )}
                    <div>
                        <Switch
                            label="Aktif"
                            checked={form.data.is_enabled === true}
                            onCheckedChange={(checked) => form.setData('is_enabled', checked)}
                        />
                        {form.errors.is_enabled && (
                            <p className="mt-1 text-sm text-destructive-text">
                                {form.errors.is_enabled}
                            </p>
                        )}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {fields.map((field) => {
                            const value = String(form.data[field.key] ?? '');
                            const error = form.errors[field.key];

                            if (field.kind === 'select') {
                                return (
                                    <NativeSelect
                                        key={field.key}
                                        id={idOf(field.key)}
                                        label={field.label}
                                        options={field.options}
                                        value={value}
                                        error={error}
                                        onChange={(event) =>
                                            form.setData(field.key, event.target.value)
                                        }
                                    />
                                );
                            }

                            if (field.kind === 'secret') {
                                return (
                                    <Input
                                        key={field.key}
                                        id={idOf(field.key)}
                                        type="password"
                                        autoComplete="new-password"
                                        label={field.label}
                                        placeholder={card.secrets[field.key] ?? 'Belum diisi'}
                                        hint="Kosongkan bila tidak diganti."
                                        value={value}
                                        error={error}
                                        onChange={(event) =>
                                            form.setData(field.key, event.target.value)
                                        }
                                    />
                                );
                            }

                            return (
                                <Input
                                    key={field.key}
                                    id={idOf(field.key)}
                                    label={field.label}
                                    autoComplete="off"
                                    inputMode={field.numeric ? 'numeric' : undefined}
                                    value={value}
                                    error={error}
                                    onChange={(event) =>
                                        form.setData(field.key, event.target.value)
                                    }
                                />
                            );
                        })}
                    </div>
                    {webhook && (
                        <div className="flex items-end gap-2">
                            <div className="min-w-0 flex-1">
                                <Input
                                    id={idOf('webhook')}
                                    label={webhook.label}
                                    hint={webhook.note}
                                    value={webhook.url}
                                    readOnly
                                />
                            </div>
                            <div className="pb-7">
                                <CopyButton value={webhook.url} />
                            </div>
                        </div>
                    )}
                </CardContent>
                <CardFooter className="flex-wrap gap-3">
                    <Button type="submit" disabled={!twoFactorEnabled || form.processing}>
                        Simpan
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        disabled={card.state === 'empty' || running}
                        loading={running}
                        onClick={test}
                    >
                        {running ? 'Menguji…' : 'Uji koneksi'}
                    </Button>
                    <p className="text-sm text-muted-foreground">
                        {provider === 'mail'
                            ? 'Email uji dikirim ke alamat Anda. Uji koneksi memakai pengaturan yang sudah disimpan.'
                            : 'Uji koneksi memakai pengaturan yang sudah disimpan.'}
                    </p>
                    {testError && (
                        <p role="alert" className="w-full text-sm text-destructive-text">
                            {testError}
                        </p>
                    )}
                </CardFooter>
            </form>
            <Modal
                open={confirming}
                onClose={() => {
                    setConfirming(false);
                    form.setData('code', '');
                    form.clearErrors('code');
                }}
                title="Konfirmasi dengan kode autentikasi"
                size="sm"
            >
                <form onSubmit={save} className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        {`Simpan integrasi ${label}. Masukkan kode dari aplikasi autentikator atau satu kode pemulihan.`}
                    </p>
                    <Input
                        id={idOf('code')}
                        label="Kode autentikasi"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        autoFocus
                        value={String(form.data.code ?? '')}
                        error={codeError}
                        onChange={(event) => form.setData('code', event.target.value)}
                    />
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setConfirming(false);
                                form.setData('code', '');
                                form.clearErrors('code');
                            }}
                        >
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            loading={form.processing}
                            disabled={String(form.data.code ?? '').trim() === ''}
                        >
                            Simpan
                        </Button>
                    </div>
                </form>
            </Modal>
        </Card>
    );
}

function FirebaseCard({ firebase }: { firebase: IntegrationsPageProps['firebase'] }) {
    const company = useCompany();

    return (
        <Card role="region" aria-labelledby="firebase-title">
            <CardHeader>
                <CardTitle id="firebase-title">Notifikasi aplikasi (Firebase)</CardTitle>
                <CardDescription>
                    Dikelola platform. Uji dari aplikasi: Akun → Notifikasi uji → Kirim notifikasi uji.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-1 text-sm">
                <p>
                    <span className="text-muted-foreground">Kredensial server: </span>
                    <span className="font-medium">
                        {firebase.configured ? 'Terpasang' : 'Belum terpasang'}
                    </span>
                </p>
                <p>
                    <span className="text-muted-foreground">Pengiriman terakhir berhasil: </span>
                    <span className="font-medium">
                        {firebase.last_sent_at
                            ? formatDateTime(firebase.last_sent_at, company)
                            : 'Belum ada'}
                    </span>
                </p>
            </CardContent>
        </Card>
    );
}

export default function Integrations({
    integrations,
    webhooks,
    firebase,
    mailFallback,
    twoFactorEnabled,
}: IntegrationsPageProps) {
    const company = useCompany();
    const errors = usePage<PageProps>().props.errors;
    const anyChecking = PROVIDER_ORDER.some((provider) => integrations[provider].checking);
    const [pollGaveUp, setPollGaveUp] = useState(false);
    const { start, stop } = usePoll(POLL_MS, { only: ['integrations'] }, { autoStart: false });

    useEffect(() => {
        if (!anyChecking) {
            stop();

            return;
        }

        start();
        const timer = setTimeout(() => {
            stop();
            setPollGaveUp(true);
        }, POLL_GIVE_UP_MS);

        return () => {
            clearTimeout(timer);
            setPollGaveUp(false);
        };
        // start and stop are fresh closures over one stable poll; only the running state matters.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [anyChecking]);

    const webhookOf: Partial<Record<IntegrationProvider, WebhookSpec>> = {
        midtrans: {
            label: 'URL notifikasi',
            url: webhooks.midtrans,
            note: 'Dikirim otomatis ke Midtrans bila alamat aplikasi memakai https.',
        },
        whatsapp: {
            label: 'URL webhook',
            url: webhooks.whatsapp,
            note: 'Tempel di Meta App → WhatsApp → Configuration bersama verify token.',
        },
    };

    return (
        <AdminLayout title="Integrasi">
            <div className="space-y-6">
                <PageHeader
                    title="Integrasi"
                    subtitle={`Akun layanan milik ${company?.name ?? 'perusahaan ini'}. Rahasia hanya bisa diganti, tidak bisa dilihat lagi.`}
                />
                {!twoFactorEnabled && (
                    <Alert variant="warning">
                        <AlertDescription className="flex flex-wrap items-center gap-3">
                            Aktifkan autentikasi dua langkah di Profil untuk menyimpan perubahan.
                            <Button asChild variant="outline" size="sm">
                                <Link href={route('profile.edit')}>Buka Profil</Link>
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}
                {errors.conflict && (
                    <Alert variant="warning">
                        <AlertDescription>{errors.conflict}</AlertDescription>
                    </Alert>
                )}
                {anyChecking && pollGaveUp && (
                    <Alert variant="info">
                        <AlertDescription>
                            Uji koneksi masih berjalan; muat ulang halaman nanti.
                        </AlertDescription>
                    </Alert>
                )}
                {PROVIDER_ORDER.map((provider) => (
                    <IntegrationFormCard
                        key={provider}
                        card={integrations[provider]}
                        twoFactorEnabled={twoFactorEnabled}
                        webhook={webhookOf[provider]}
                        mailFallback={mailFallback.from_address}
                    />
                ))}
                <FirebaseCard firebase={firebase} />
            </div>
        </AdminLayout>
    );
}
