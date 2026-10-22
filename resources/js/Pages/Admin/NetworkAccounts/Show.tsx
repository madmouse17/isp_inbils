import type { FormEvent, ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { Link, router, useForm, usePoll } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { format } from 'date-fns';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Alert,
    AlertDescription,
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertTitle,
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyCell,
    EnumBadge,
    Input,
    NativeSelect,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import { ConfirmDialog } from '@/Components/composite/ConfirmDialog';
import { CopyButton } from '@/Components/composite/CopyButton';
import { desiredStateExplanation } from '@/Components/composite/NetworkAccessBadges';
import { SuggestionChip } from '@/Components/composite/SuggestionChip';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/format';
import {
    NETWORK_DESIRED_STATE,
    NETWORK_ISOLATED_BECAUSE,
    NETWORK_ONLINE_STATE,
    NETWORK_SERVICE_TYPE,
    NETWORK_SYNC_STATUS,
    SUBSCRIPTION_STATUS,
    labelOf,
    optionsOf,
} from '@/lib/statuses';
import type {
    NetworkAccount,
    NetworkAccountSubscription,
    NetworkDesiredState,
    RadiusSession,
    NetworkServiceType,
} from '@/types/network-asset';

/** The IPv4 address IPAM holds for the subscription; the pool is null for a viewer without ipam.view. */
interface StaticAddress {
    address: string;
    prefix: string | null;
}

interface ShowProps extends Record<string, unknown> {
    subscription: NetworkAccountSubscription;
    /** Lower-cased company code; null when it cannot be used as a RADIUS realm. */
    realm: string | null;
    account: NetworkAccount | null;
    /** Null while RADIUS is off or unreachable, or when the subscriber is offline. */
    session: RadiusSession | null;
    usage: { month: string; input_octets: number; output_octets: number } | null;
    /** What RADIUS should hand out as Framed-IP-Address; null while the subscription takes a dynamic address. */
    staticAddress: StaticAddress | null;
    desiredState: NetworkDesiredState;
    isolatedBecause: ('suspended' | 'blocked')[];
    radiusEnabled: boolean;
    can: { manage: boolean; reveal: boolean };
    errors?: Record<string, string | undefined>;
}

const serviceTypeOptions = optionsOf(NETWORK_SERVICE_TYPE);

/** The username rule of the field hint: 3 to 40 lower-case letters, digits, dots, hyphens or underscores. */
const USERNAME_PATTERN = /^[a-z0-9._-]{3,40}$/;

/** While a session was cut and the router has not come back, the page asks again this often, and stops after the give-up time. */
const POLL_MS = 5000;
const POLL_GIVE_UP_MS = 5 * 60_000;
/** "Sesi diputus ..." is worth saying for this long after the cut; later an offline account is just offline. */
const RECONNECT_NOTE_MS = 30 * 60_000;

/** Reads the one-time secret out of the flash data of a visit; the shape is fixed by NetworkAccountController. */
function secretFrom(flash: Record<string, unknown>): string | null {
    const sent = flash.network_account as { secret?: string } | undefined;

    return sent?.secret ?? null;
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1 sm:grid-cols-[10rem_1fr]">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 wrap-break-word text-foreground">{children}</dd>
        </div>
    );
}

const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

function formatBytes(bytes: number): string {
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < BYTE_UNITS.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${unit === 0 ? value : value.toFixed(2)} ${BYTE_UNITS[unit]}`;
}

/** The username is `local@realm`; staff edit the local part, the realm is fixed by the company. */
function localPart(username: string, realm: string | null): string {
    const suffix = realm ? `@${realm}` : '';

    return suffix && username.endsWith(suffix) ? username.slice(0, -suffix.length) : username;
}

/** `14:05` for a moment today; an earlier day keeps its date, so "sejak 00:22" never names the wrong day. */
function sinceOf(moment: string): string {
    const at = new Date(moment);

    return at.toDateString() === new Date().toDateString()
        ? format(at, 'HH:mm')
        : formatDateTime(moment);
}

/** Milliseconds from a moment of the server to now; infinite for no moment. */
function millisSince(moment: string | null): number {
    return moment === null ? Infinity : Date.now() - new Date(moment).getTime();
}

function UsernameField({
    value,
    realm,
    error,
    suggestion,
    onChange,
}: {
    value: string;
    realm: string;
    error?: string;
    /** Offered while the field is empty. */
    suggestion?: string | null;
    onChange: (value: string) => void;
}) {
    const describedBy = error ? 'account-username-error' : 'account-username-hint';

    return (
        <div className="space-y-1.5">
            <label htmlFor="account-username" className="text-sm leading-none font-medium">
                Nama pengguna
                <span aria-hidden="true" className="text-destructive-text">
                    {' '}
                    *
                </span>
            </label>
            <div className="flex items-center gap-2">
                <Input
                    id="account-username"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    autoComplete="off"
                    aria-describedby={describedBy}
                    aria-invalid={error ? true : undefined}
                    required
                />
                <span className="font-mono text-sm break-all text-muted-foreground">@{realm}</span>
            </div>
            {error ? (
                <p id="account-username-error" className="text-sm text-destructive-text">
                    {error}
                </p>
            ) : (
                <p id="account-username-hint" className="text-sm text-muted-foreground">
                    3 sampai 40 huruf kecil, angka, titik, tanda hubung, atau garis bawah.
                </p>
            )}
            {suggestion && value === '' && (
                <SuggestionChip value={suggestion} onUse={() => onChange(suggestion)} />
            )}
        </div>
    );
}

function CreateAccountCard({
    subscriptionId,
    realm,
    customerCode,
    onSecret,
}: {
    subscriptionId: string;
    realm: string | null;
    customerCode: string | null;
    onSecret: (secret: string) => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        service_type: 'pppoe' as NetworkServiceType,
        username: '',
        password: '',
    });
    // The customer code, lower-cased, when that already is a valid username.
    const lowered = customerCode?.toLowerCase() ?? '';
    const suggestion = USERNAME_PATTERN.test(lowered) ? lowered : null;

    if (realm === null) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Buat akun jaringan</CardTitle>
                </CardHeader>
                <CardContent>
                    <Alert variant="warning">
                        <AlertDescription>
                            Kode perusahaan tidak dapat dipakai sebagai realm RADIUS (huruf kecil,
                            angka, dan tanda hubung, maksimal 24 karakter). Ubah kode perusahaan
                            sebelum membuat akun jaringan.
                        </AlertDescription>
                    </Alert>
                </CardContent>
            </Card>
        );
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.subscriptions.network-account.store', subscriptionId), {
            preserveScroll: true,
            onFlash: (flash) => {
                const secret = secretFrom(flash);
                if (secret) onSecret(secret);
            },
            onFinish: () => setData('password', ''),
        });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Buat akun jaringan</CardTitle>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <NativeSelect
                        label="Jenis layanan"
                        value={data.service_type}
                        onChange={(event) =>
                            setData('service_type', event.target.value as NetworkServiceType)
                        }
                        options={serviceTypeOptions}
                        error={errors.service_type}
                        required
                    />
                    <UsernameField
                        value={data.username}
                        realm={realm}
                        suggestion={suggestion}
                        error={errors.username}
                        onChange={(value) => setData('username', value)}
                    />
                    <Input
                        label="Kata sandi"
                        type="password"
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                        hint="6 sampai 64 karakter tanpa spasi. Kosongkan untuk dibuatkan otomatis."
                        error={errors.password}
                    />
                    <Button
                        type="submit"
                        className="min-h-11"
                        loading={processing}
                        disabled={processing}
                    >
                        Buat akun jaringan
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function EditAccountCard({
    subscription,
    account,
    realm,
}: {
    subscription: NetworkAccountSubscription;
    account: NetworkAccount;
    realm: string;
}) {
    const terminated = subscription.status === 'terminated';
    const { data, setData, put, processing, errors, reset } = useForm({
        service_type: account.service_type,
        username: localPart(account.username, realm),
        password: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('admin.subscriptions.network-account.update', subscription.id), {
            preserveScroll: true,
            onSuccess: () => reset('password'),
        });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Ubah akun jaringan</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {terminated && (
                    <Alert variant="warning">
                        <AlertDescription>
                            Langganan yang sudah berhenti tidak dapat punya akun jaringan.
                        </AlertDescription>
                    </Alert>
                )}
                <form onSubmit={submit} className="space-y-4">
                    <fieldset disabled={terminated} className="space-y-4 min-w-0">
                        <NativeSelect
                            label="Jenis layanan"
                            value={data.service_type}
                            onChange={(event) =>
                                setData('service_type', event.target.value as NetworkServiceType)
                            }
                            options={serviceTypeOptions}
                            error={errors.service_type}
                            required
                        />
                        <UsernameField
                            value={data.username}
                            realm={realm}
                            error={errors.username}
                            onChange={(value) => setData('username', value)}
                        />
                        <Input
                            label="Kata sandi"
                            type="password"
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                            hint="Kosongkan untuk mempertahankan kata sandi saat ini."
                            error={errors.password}
                        />
                        <Button
                            type="submit"
                            className="min-h-11"
                            loading={processing}
                            disabled={processing || terminated}
                        >
                            Simpan perubahan
                        </Button>
                    </fieldset>
                </form>
            </CardContent>
        </Card>
    );
}

export default function Show({
    subscription,
    account,
    desiredState,
    realm,
    session,
    usage,
    staticAddress,
    isolatedBecause,
    radiusEnabled,
    can,
    errors = {},
}: ShowProps) {
    // The secret comes as flash data, in no prop, so the page keeps it only until it is dismissed or
    // replaced. Nothing else ever receives it.
    const [secret, setSecret] = useState<string | null>(null);
    const [revealing, setRevealing] = useState(false);
    const [confirmingReveal, setConfirmingReveal] = useState(false);
    const [resyncing, setResyncing] = useState(false);
    const [confirmingResync, setConfirmingResync] = useState(false);
    const canOpenIntegrations = usePermission().can('company.integrations.manage');

    const reveal = () => {
        setRevealing(true);
        router.post(
            route('admin.subscriptions.network-account.reveal', subscription.id),
            {},
            {
                preserveScroll: true,
                onFlash: (flash) => setSecret(secretFrom(flash) ?? null),
                onFinish: () => {
                    setRevealing(false);
                    setConfirmingReveal(false);
                },
            },
        );
    };

    const resync = () => {
        setResyncing(true);
        router.post(
            route('admin.subscriptions.network-account.resync', subscription.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setResyncing(false);
                    setConfirmingResync(false);
                },
            },
        );
    };

    const speedProfile = subscription.speed_profile;
    // An open row that nothing updated for 15 minutes is a router that rebooted without sending the Stop: not online.
    const live = session !== null && !session.stale;
    // A session only gets its address when it starts, so one that predates an assignment keeps the old address until it is cut.
    const framedIp = live ? session.framed_ip : null;
    const addressMismatch =
        staticAddress !== null && framedIp !== null && framedIp !== staticAddress.address
            ? { framedIp, expected: staticAddress.address }
            : null;
    // A cut that went through and a router that has not redialled yet; the page follows until it does.
    const reconnecting =
        radiusEnabled &&
        !live &&
        !account?.pending_disconnect_at &&
        millisSince(account?.last_disconnect_at ?? null) < RECONNECT_NOTE_MS;
    const waiting = radiusEnabled && (Boolean(account?.pending_disconnect_at) || reconnecting);
    const [pollGaveUp, setPollGaveUp] = useState(false);
    const { start, stop } = usePoll(
        POLL_MS,
        { only: ['account', 'session'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (!waiting) {
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
        // start and stop are fresh closures over one stable poll; only the waiting state matters.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [waiting]);

    return (
        <AdminLayout title={`Akses jaringan ${subscription.code}`}>
            <div className="space-y-6">
                <PageHeader
                    title="Akses jaringan"
                    subtitle={`Langganan ${subscription.code}`}
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.subscriptions.show', subscription.id)}>
                                    Kembali
                                </Link>
                            </Button>
                            {account && can.manage ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    loading={resyncing}
                                    onClick={() => setConfirmingResync(true)}
                                >
                                    Sinkron ulang
                                </Button>
                            ) : null}
                            {account && can.reveal ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setConfirmingReveal(true)}
                                >
                                    Tampilkan kata sandi
                                </Button>
                            ) : null}
                        </>
                    }
                />

                {radiusEnabled && account?.pending_disconnect_at ? (
                    <Alert variant="warning">
                        <AlertDescription className="flex flex-wrap items-center gap-3">
                            Router belum menjawab sejak {sinceOf(account.pending_disconnect_at)}.
                            Pemutusan sesi dicoba lagi otomatis.
                            {can.manage && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    loading={resyncing}
                                    onClick={resync}
                                >
                                    Coba sekarang
                                </Button>
                            )}
                        </AlertDescription>
                    </Alert>
                ) : null}

                {reconnecting && account?.last_disconnect_at ? (
                    <Alert variant="info">
                        <AlertDescription>
                            Sesi diputus {sinceOf(account.last_disconnect_at)}, menunggu router
                            menyambung ulang.
                            {pollGaveUp
                                ? ' Pembaruan otomatis berhenti; muat ulang halaman untuk memeriksa lagi.'
                                : ''}
                        </AlertDescription>
                    </Alert>
                ) : null}

                {addressMismatch ? (
                    <Alert variant="warning">
                        <AlertDescription className="flex flex-wrap items-center gap-3">
                            <span>
                                Sesi memakai{' '}
                                <span className="font-mono">{addressMismatch.framedIp}</span>, bukan
                                alamat statis{' '}
                                <span className="font-mono">{addressMismatch.expected}</span>.
                                Sinkron ulang agar router memberi alamat yang benar.
                            </span>
                            {can.manage ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setConfirmingResync(true)}
                                >
                                    Sinkron ulang dan putuskan sesi
                                </Button>
                            ) : null}
                        </AlertDescription>
                    </Alert>
                ) : null}

                {!radiusEnabled && (
                    <Alert variant="warning">
                        <AlertDescription className="flex flex-wrap items-center gap-3">
                            RADIUS belum dikonfigurasi untuk perusahaan ini. Perubahan disimpan
                            tetapi tidak dikirim ke FreeRADIUS.
                            {canOpenIntegrations && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={route('admin.company.integrations.edit')}>
                                        Atur di Pengaturan → Integrasi
                                    </Link>
                                </Button>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                {secret !== null && (
                    <Alert variant="warning">
                        <AlertTitle>Kata sandi hanya tampil sekali</AlertTitle>
                        <AlertDescription className="space-y-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <code className="rounded-md bg-muted px-2 py-1 font-mono text-base break-all text-foreground select-all">
                                    {secret}
                                </code>
                                <CopyButton value={secret} label="Salin kata sandi" />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setSecret(null)}
                                >
                                    Tutup
                                </Button>
                            </div>
                            <p>
                                Salin sekarang. Kata sandi tidak disimpan setelah pesan ini ditutup
                                atau halaman ditinggalkan.
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                {(errors.subscription || errors.realm) && (
                    <Alert variant="danger">
                        <AlertDescription>{errors.subscription ?? errors.realm}</AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-6 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Langganan</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-3 text-sm">
                                <Row label="Langganan">{subscription.code}</Row>
                                <Row label="Status">
                                    <EnumBadge
                                        map={SUBSCRIPTION_STATUS}
                                        value={subscription.status}
                                    />
                                </Row>
                                <Row label="Pelanggan">
                                    {subscription.customer ? (
                                        <Link
                                            href={route(
                                                'admin.customers.show',
                                                subscription.customer.id,
                                            )}
                                            className="font-medium text-brand-600 hover:underline dark:text-brand-400"
                                        >
                                            {subscription.customer.name}
                                        </Link>
                                    ) : (
                                        <EmptyCell />
                                    )}
                                </Row>
                                <Row label="Profil kecepatan">
                                    {speedProfile ? (
                                        <>
                                            {speedProfile.name}
                                            {speedProfile.radius_profile_name
                                                ? `, grup RADIUS ${speedProfile.radius_profile_name}`
                                                : ''}
                                        </>
                                    ) : (
                                        <EmptyCell />
                                    )}
                                </Row>
                                <Row label="POP layanan">
                                    {subscription.serving_pop?.name ?? <EmptyCell />}
                                </Row>
                                <Row label="Status yang diharapkan">
                                    <EnumBadge map={NETWORK_DESIRED_STATE} value={desiredState} />
                                    {isolatedBecause.length > 0 ? (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Diisolir karena{' '}
                                            {isolatedBecause
                                                .map((reason) =>
                                                    labelOf(
                                                        NETWORK_ISOLATED_BECAUSE,
                                                        reason,
                                                    ).toLowerCase(),
                                                )
                                                .join(' dan ')}
                                            .
                                        </p>
                                    ) : null}
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {desiredStateExplanation[desiredState]}
                                    </p>
                                </Row>
                            </dl>
                            {!speedProfile?.radius_profile_name && (
                                <Alert variant="warning" className="mt-4">
                                    <AlertDescription>
                                        Profil kecepatan paket ini belum punya nama profil RADIUS.
                                        Isi dulu di profil kecepatan sebelum membuat akun jaringan.
                                    </AlertDescription>
                                </Alert>
                            )}
                        </CardContent>
                    </Card>

                    {account && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Akun jaringan</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="space-y-3 text-sm">
                                    <Row label="Jenis layanan">
                                        <EnumBadge
                                            map={NETWORK_SERVICE_TYPE}
                                            value={account.service_type}
                                        />
                                    </Row>
                                    <Row label="Nama pengguna">
                                        <span className="font-mono break-all">
                                            {account.username}
                                        </span>
                                    </Row>
                                    <Row label="Status sinkronisasi">
                                        <EnumBadge
                                            map={NETWORK_SYNC_STATUS}
                                            value={account.sync_status}
                                        />
                                        {account.last_error && (
                                            <p
                                                className={
                                                    account.sync_status === 'failed'
                                                        ? 'mt-1 text-xs text-destructive-text'
                                                        : 'mt-1 text-xs text-muted-foreground'
                                                }
                                            >
                                                {account.last_error}
                                            </p>
                                        )}
                                        {account.disconnect_pending && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Pemutusan sesi yang terbuka masih tertunda.
                                            </p>
                                        )}
                                    </Row>
                                    <Row label="Grup RADIUS">
                                        {account.provisioned_group
                                            ? `${account.provisioned_group} sejak ${formatDateTime(account.provisioned_at)}`
                                            : 'Belum ada di RADIUS'}
                                    </Row>
                                    {staticAddress ? (
                                        <Row label="IP statis diharapkan">
                                            <span className="font-mono break-all">
                                                {staticAddress.address}
                                            </span>
                                            {staticAddress.prefix ? (
                                                <>
                                                    {' '}
                                                    (IPAM{' '}
                                                    <span className="font-mono">
                                                        {staticAddress.prefix}
                                                    </span>
                                                    )
                                                </>
                                            ) : null}
                                        </Row>
                                    ) : null}
                                    <Row label="Online">
                                        <EnumBadge
                                            map={NETWORK_ONLINE_STATE}
                                            value={
                                                !live
                                                    ? false
                                                    : account.pending_disconnect_at
                                                      ? 'pending_disconnect'
                                                      : true
                                            }
                                        />
                                    </Row>
                                    {session && (
                                        <>
                                            <Row label="Alamat">
                                                <span className="font-mono break-all">
                                                    {session.framed_ip ?? '-'}
                                                </span>{' '}
                                                <Badge variant={staticAddress ? 'info' : 'neutral'}>
                                                    {staticAddress ? 'Statis' : 'Dinamis'}
                                                </Badge>
                                            </Row>
                                            <Row label="NAS">
                                                {session.nas_label ?? session.nas_ip}
                                            </Row>
                                            <Row label="Sesi mulai">
                                                {formatDateTime(session.started_at)}
                                            </Row>
                                            <Row label="Terakhir terlihat">
                                                {sinceOf(session.last_seen_at)}
                                                {session.stale ? (
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        Data sesi lebih dari 15 menit
                                                    </p>
                                                ) : null}
                                            </Row>
                                        </>
                                    )}
                                    <Row label="Bulan ini">
                                        {usage ? (
                                            `Unggah ${formatBytes(usage.input_octets)}, unduh ${formatBytes(usage.output_octets)}`
                                        ) : radiusEnabled ? (
                                            'Pemakaian belum tersedia. RADIUS tidak menjawab.'
                                        ) : (
                                            <EmptyCell />
                                        )}
                                    </Row>
                                </dl>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {account && can.manage && realm !== null && (
                    <EditAccountCard
                        key={account.id}
                        subscription={subscription}
                        account={account}
                        realm={realm}
                    />
                )}

                {!account && can.manage && (
                    <CreateAccountCard
                        subscriptionId={subscription.id}
                        realm={realm}
                        customerCode={subscription.customer?.code ?? null}
                        onSecret={setSecret}
                    />
                )}

                {!account && !can.manage && (
                    <p className="text-sm text-muted-foreground">
                        Langganan ini belum punya akun jaringan.
                    </p>
                )}
            </div>

            <AlertDialog
                open={confirmingReveal}
                onOpenChange={(open) => {
                    if (!revealing) setConfirmingReveal(open);
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Tampilkan kata sandi?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Kata sandi ditampilkan sekali di halaman ini dan pengungkapannya dicatat
                            di log audit.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={revealing}>Batal</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={revealing}
                            onClick={(event) => {
                                event.preventDefault();
                                reveal();
                            }}
                        >
                            Tampilkan
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <ConfirmDialog
                open={confirmingResync}
                title={`Sinkron ulang akun ${account?.username ?? subscription.code}?`}
                description={
                    radiusEnabled
                        ? 'Akun ditulis ulang ke FreeRADIUS dan sesi yang sedang terbuka diputus. Pelanggan tersambung lagi otomatis, tetapi koneksinya terputus sebentar.'
                        : 'RADIUS belum dikonfigurasi. Perubahan disimpan dan dikirim ke router setelah RADIUS aktif.'
                }
                confirmLabel="Sinkron ulang"
                processing={resyncing}
                onConfirm={resync}
                onClose={() => setConfirmingResync(false)}
            />
        </AdminLayout>
    );
}
