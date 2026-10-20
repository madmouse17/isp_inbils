import type { FormEvent, ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyCell,
    EnumBadge,
    Input,
    Modal,
    SearchSelect,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import { ConfirmDialog } from '@/Components/composite/ConfirmDialog';
import { ConfirmActionDialog } from '@/Components/composite/platform/ConfirmActionDialog';
import { BlockedBadge, SubscriptionBlockModal } from '@/Components/composite/NetworkAccessBadges';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { SUBSCRIPTION_STATUS } from '@/lib/statuses';
import type { ServiceSubscription } from '@/types/models';
import { SubscriptionNetworkPanels } from '@/Components/composite/SubscriptionNetworkPanels';
import type { DropPort, DropPortOption, StaticPool, SubscriptionIpAddress } from '@/types/ipam-odn';
import { usePermission } from '@/hooks/usePermission';

/** The terms of the package version the subscription was sold on (`ServicePackageVersionResource`). */
interface PackageVersion {
    version: number;
    valid_from: string;
    price_mrc: string;
    price_otc: string;
    bandwidth_profile?: { name: string; download_mbps: number; upload_mbps: number } | null;
    speed_profile?: { name: string; download_max_mbps: number; upload_max_mbps: number } | null;
    sla_tier?: {
        name: string;
        response_time_hours: number;
        resolution_time_hours: number;
    } | null;
    category?: { name: string } | null;
}

type SubscriptionDetail = ServiceSubscription & {
    package_version?: PackageVersion | null;
    /** False when the package has a newer version than the one this subscription holds. */
    package_version_is_latest?: boolean;
};

/** An active package a subscription can move to; `price_mrc` is its latest version's monthly price. */
interface PackageOption {
    id: string;
    code: string;
    name: string;
    price_mrc: string;
}

interface ShowProps extends Record<string, unknown> {
    subscription: { data: SubscriptionDetail };
    ipAddresses: SubscriptionIpAddress[];
    /** Present only for holders of ipam.assign. */
    staticPools?: StaticPool[];
    dropPort: DropPort | null;
    /** Free ODP ports, found by typing an ODP code; odn.assign only. */
    dropPortOptions?: DropPortOption[];
    /** Active packages, loaded when the Ganti paket dialog opens; only for holders of customer.subscription.manage. */
    packageOptions?: PackageOption[];
    /** The company's RADIUS switch: with it off the block dialog promises nothing about the router. */
    radiusEnabled: boolean;
    /** The day a reactivation moves the next invoice to (`Y-m-d`); null unless the subscription is suspended. */
    reactivationNextInvoiceDate: string | null;
    can: { assignIp: boolean; assignDrop: boolean; block: boolean; changePackage: boolean };
}

function DetailRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 break-words">{children}</dd>
        </>
    );
}

interface ChangePackageModalProps {
    subscription: SubscriptionDetail;
    /** `undefined` until the dialog has asked for it. */
    packageOptions: PackageOption[] | undefined;
    onClose: () => void;
}

/**
 * Moves the subscription to the latest version of a package, the one place a subscription changes
 * package. It mounts only while open, so each opening starts from the current package and no error.
 */
function ChangePackageModal({ subscription, packageOptions, onClose }: ChangePackageModalProps) {
    const currentId = subscription.service_package_id ?? '';
    const currentName = subscription.package?.name;
    const [packageId, setPackageId] = useState(currentId);
    // null until an amount is typed: the field then follows the price of the chosen package.
    const [typedAmount, setTypedAmount] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [loadingOptions, setLoadingOptions] = useState(packageOptions === undefined);

    useEffect(() => {
        if (packageOptions !== undefined) return;

        router.reload({ only: ['packageOptions'], onFinish: () => setLoadingOptions(false) });
    }, [packageOptions]);

    const options = useMemo(
        () =>
            (packageOptions ?? []).map((option) => ({
                value: option.id,
                label: option.name,
                description: `${option.code} · ${formatMoney(option.price_mrc)}/bln`,
            })),
        [packageOptions],
    );

    const chosen = packageOptions?.find((option) => option.id === packageId);
    const amount = typedAmount ?? (chosen ? String(Number(chosen.price_mrc)) : '');
    const amountNumber = amount.trim() === '' ? null : Number(amount);
    // The server refuses a move to the version the subscription already holds, so the button waits for another choice.
    const alreadyLatest =
        packageId === currentId && subscription.package_version_is_latest === true;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        router.post(
            route('admin.subscriptions.change-package', subscription.id),
            {
                service_package_id: packageId,
                mrc_amount: amount.trim() === '' ? null : amount.trim(),
            },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setErrors({});
                },
                onSuccess: onClose,
                onError: setErrors,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Modal
            open
            onClose={() => {
                if (!processing) onClose();
            }}
            title="Ganti paket"
        >
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-muted-foreground">
                    Langganan pindah ke versi terbaru paket ini. Tagihan berikutnya memakai biaya
                    bulanan ini. Biaya pemasangan berubah hanya bila langganan masih menunggu.
                </p>
                <SearchSelect
                    id="change-package"
                    label="Paket"
                    value={packageId}
                    onChange={(value) => {
                        setPackageId(value);
                        setTypedAmount(null);
                        setErrors({});
                    }}
                    options={options}
                    selected={
                        currentId
                            ? { value: currentId, label: currentName ?? 'Paket saat ini' }
                            : null
                    }
                    loading={loadingOptions}
                    placeholder="Cari paket"
                    emptyText="Tidak ada paket aktif yang cocok."
                    error={errors.service_package_id}
                    hint={
                        alreadyLatest
                            ? 'Langganan sudah memakai versi terbaru paket ini. Pilih paket lain untuk memindahkannya.'
                            : undefined
                    }
                    required
                />
                <Input
                    id="change-package-mrc"
                    label="Biaya bulanan"
                    inputMode="decimal"
                    value={amount}
                    onChange={(event) => setTypedAmount(event.target.value)}
                    placeholder="Otomatis dari harga paket"
                    error={errors.mrc_amount}
                    hint={
                        amountNumber !== null && Number.isFinite(amountNumber)
                            ? formatMoney(amountNumber)
                            : undefined
                    }
                />
                <div className="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Batal
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        loading={processing}
                        disabled={packageId === '' || alreadyLatest}
                    >
                        Pindahkan
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

export default function Show({
    subscription,
    ipAddresses,
    staticPools,
    dropPort,
    dropPortOptions,
    packageOptions,
    radiusEnabled,
    reactivationNextInvoiceDate,
    can: { block: canBlock, changePackage: canChangePackage, ...assignable },
}: ShowProps) {
    const s = subscription.data;
    const version = s.package_version;
    const can = usePermission();
    const [actionModal, setActionModal] = useState<'suspend' | null>(null);
    const [changeOpen, setChangeOpen] = useState(false);
    const [pending, setPending] = useState<'activate' | 'reactivate' | 'terminate' | null>(null);
    const [sending, setSending] = useState(false);
    const [refusal, setRefusal] = useState<Record<string, string>>({});
    const [blockOpen, setBlockOpen] = useState(false);
    const { data, setData, post, processing } = useForm({ reason: '' });

    const doAction = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.subscriptions.suspend', s.id), {
            onSuccess: () => setActionModal(null),
        });
    };

    const closeConfirm = () => {
        setPending(null);
        setRefusal({});
    };

    const send = (action: 'activate' | 'reactivate' | 'terminate', payload: { reason?: string }) =>
        router.post(route(`admin.subscriptions.${action}`, s.id), payload, {
            onStart: () => setSending(true),
            onSuccess: closeConfirm,
            onError: setRefusal,
            onFinish: () => setSending(false),
        });

    return (
        <AdminLayout title={`Langganan ${s.code}`}>
            <div className="space-y-6">
                <PageHeader
                    title={`Langganan ${s.code}`}
                    subtitle="Rincian, status, dan jaringan langganan ini."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link
                                href={route(
                                    'admin.customers.subscriptions.index',
                                    s.customer_id ?? '',
                                )}
                            >
                                Kembali
                            </Link>
                        </Button>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Rincian langganan</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-3 text-sm">
                                <DetailRow label="Pelanggan">
                                    {s.customer?.name ?? `#${s.customer_id}`}
                                </DetailRow>
                                <DetailRow label="Alamat pemasangan">
                                    {s.installation_address?.label ??
                                        `#${s.installation_address_id}`}
                                </DetailRow>
                                <DetailRow label="POP pelayan">
                                    {s.serving_pop?.name ?? <EmptyCell />}
                                </DetailRow>
                                <DetailRow label="Biaya bulanan">
                                    {formatMoney(s.mrc_amount)}
                                </DetailRow>
                                <DetailRow label="Biaya pemasangan">
                                    {formatMoney(s.otc_installation_fee)}
                                </DetailRow>
                                <DetailRow label="Tanggal tagihan">{s.billing_day}</DetailRow>
                                <DetailRow label="Masa kontrak">
                                    {s.contract_months ? (
                                        `${s.contract_months} bulan`
                                    ) : (
                                        <EmptyCell />
                                    )}
                                </DetailRow>
                                {s.notes ? <DetailRow label="Catatan">{s.notes}</DetailRow> : null}
                            </dl>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-[max-content_1fr] items-center gap-x-6 gap-y-3 text-sm">
                                <DetailRow label="Status">
                                    <EnumBadge map={SUBSCRIPTION_STATUS} value={s.status} />
                                </DetailRow>
                                {s.blocked_at && (
                                    <>
                                        <DetailRow label="Internet">
                                            <BlockedBadge />
                                        </DetailRow>
                                        <DetailRow label="Alasan blokir">
                                            {s.block_reason ?? <EmptyCell />}
                                        </DetailRow>
                                        <DetailRow label="Diblokir oleh">
                                            {s.blocked_by?.name ?? '-'},{' '}
                                            {formatDateTime(s.blocked_at)}
                                        </DetailRow>
                                    </>
                                )}
                                <DetailRow label="Tanggal aktivasi">
                                    {formatDate(s.activation_date)}
                                </DetailRow>
                                <DetailRow label="Tanggal berakhir">
                                    {formatDate(s.expiration_date)}
                                </DetailRow>
                                <DetailRow label="Tagihan berikutnya">
                                    {formatDate(s.next_invoice_date)}
                                </DetailRow>
                                <DetailRow label="Berhenti pada">
                                    {formatDateTime(s.terminated_at)}
                                </DetailRow>
                                {s.terminated_reason ? (
                                    <DetailRow label="Alasan berhenti">
                                        {s.terminated_reason}
                                    </DetailRow>
                                ) : null}
                            </dl>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="space-y-0 flex-row flex-wrap items-center justify-between">
                            <CardTitle>Paket</CardTitle>
                            {canChangePackage && s.status !== 'terminated' ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setChangeOpen(true)}
                                >
                                    Ganti paket
                                </Button>
                            ) : null}
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-3 text-sm">
                                <DetailRow label="Nama paket">
                                    <div className="space-y-0.5">
                                        <p className="flex flex-wrap items-center gap-2">
                                            <span>
                                                {s.package?.name ?? `#${s.service_package_id}`}
                                            </span>
                                            {s.package_version_is_latest === false ? (
                                                <Badge variant="info">Versi terbaru tersedia</Badge>
                                            ) : null}
                                        </p>
                                        {version ? (
                                            <p className="text-muted-foreground">
                                                {`Versi ${version.version} sejak ${formatDate(version.valid_from)}`}
                                            </p>
                                        ) : null}
                                    </div>
                                </DetailRow>
                                {version ? (
                                    <>
                                        <DetailRow label="Kategori">
                                            {version.category?.name ?? <EmptyCell />}
                                        </DetailRow>
                                        <DetailRow label="Harga bulanan">
                                            {formatMoney(version.price_mrc)}
                                        </DetailRow>
                                        <DetailRow label="Harga pemasangan">
                                            {formatMoney(version.price_otc)}
                                        </DetailRow>
                                        <DetailRow label="Profil bandwidth">
                                            {version.bandwidth_profile ? (
                                                `${version.bandwidth_profile.name} (${version.bandwidth_profile.download_mbps} / ${version.bandwidth_profile.upload_mbps} Mbps)`
                                            ) : (
                                                <EmptyCell />
                                            )}
                                        </DetailRow>
                                        <DetailRow label="Profil kecepatan">
                                            {version.speed_profile ? (
                                                `${version.speed_profile.name} (${version.speed_profile.download_max_mbps} / ${version.speed_profile.upload_max_mbps} Mbps)`
                                            ) : (
                                                <EmptyCell />
                                            )}
                                        </DetailRow>
                                        <DetailRow label="Tingkat SLA">
                                            {version.sla_tier ? (
                                                `${version.sla_tier.name} (respons ${version.sla_tier.response_time_hours} jam, selesai ${version.sla_tier.resolution_time_hours} jam)`
                                            ) : (
                                                <EmptyCell />
                                            )}
                                        </DetailRow>
                                    </>
                                ) : null}
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <SubscriptionNetworkPanels
                    subscription={{
                        id: s.id,
                        code: s.code ?? '',
                        status: s.status ?? '',
                        hasServingPop: s.serving_pop_id != null,
                    }}
                    ipAddresses={ipAddresses}
                    staticPools={staticPools}
                    dropPort={dropPort}
                    dropPortOptions={dropPortOptions}
                    can={assignable}
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Aksi langganan</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-2">
                            {s.status === 'pending' && (
                                <Button type="button" onClick={() => setPending('activate')}>
                                    Aktifkan
                                </Button>
                            )}
                            {s.status === 'active' && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setActionModal('suspend')}
                                >
                                    Tangguhkan
                                </Button>
                            )}
                            {s.status === 'suspended' && (
                                <Button type="button" onClick={() => setPending('reactivate')}>
                                    Aktifkan kembali
                                </Button>
                            )}
                            {(s.status === 'active' || s.status === 'suspended') && (
                                <Button
                                    type="button"
                                    variant="danger"
                                    onClick={() => setPending('terminate')}
                                >
                                    Hentikan
                                </Button>
                            )}
                            {canBlock && s.status !== 'terminated' && (
                                <Button
                                    type="button"
                                    variant={s.blocked_at ? 'outline' : 'danger'}
                                    onClick={() => setBlockOpen(true)}
                                >
                                    {s.blocked_at ? 'Buka blokir' : 'Blokir internet'}
                                </Button>
                            )}
                            {can('network_asset.account.view') && (
                                <Button asChild variant="outline">
                                    <Link
                                        href={route(
                                            'admin.subscriptions.network-account.show',
                                            s.id,
                                        )}
                                    >
                                        Akses jaringan
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <SubscriptionBlockModal
                    target={
                        blockOpen
                            ? {
                                  id: s.id,
                                  code: s.code ?? '',
                                  status: s.status ?? '',
                                  blocked_at: s.blocked_at ?? null,
                              }
                            : null
                    }
                    radiusEnabled={radiusEnabled}
                    onClose={() => setBlockOpen(false)}
                />

                <ConfirmDialog
                    open={pending === 'activate'}
                    title={`Aktifkan langganan ${s.code}?`}
                    description="Layanan pelanggan diaktifkan dan penagihan dimulai."
                    confirmLabel="Aktifkan"
                    processing={sending}
                    error={Object.values(refusal)[0]}
                    onConfirm={() => send('activate', {})}
                    onClose={closeConfirm}
                />
                <ConfirmDialog
                    open={pending === 'reactivate'}
                    title={`Aktifkan kembali langganan ${s.code}?`}
                    description={
                        reactivationNextInvoiceDate
                            ? `Layanan yang ditangguhkan dipulihkan kembali. Tagihan berikutnya akan menjadi ${formatDate(reactivationNextInvoiceDate)}. Periode selama ditangguhkan tidak ditagih.`
                            : 'Layanan yang ditangguhkan dipulihkan kembali.'
                    }
                    confirmLabel="Aktifkan kembali"
                    processing={sending}
                    error={Object.values(refusal)[0]}
                    onConfirm={() => send('reactivate', {})}
                    onClose={closeConfirm}
                />
                {pending === 'terminate' ? (
                    <ConfirmActionDialog
                        title={`Hentikan langganan ${s.code}?`}
                        description="Layanan dihentikan permanen dan port drop dibebaskan. Langganan tidak bisa dipulihkan."
                        confirmLabel="Hentikan langganan"
                        tone="danger"
                        requirePassword={false}
                        reason
                        companyCode={s.code ?? ''}
                        processing={sending}
                        errors={refusal}
                        onClose={closeConfirm}
                        onConfirmed={({ reason }) => send('terminate', { reason })}
                    />
                ) : null}

                <Modal
                    open={actionModal !== null}
                    onClose={() => setActionModal(null)}
                    title="Tangguhkan Langganan"
                >
                    <form onSubmit={doAction} className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            Pelanggan melihat status Ditangguhkan dengan tombol Hubungi ISP.
                            Internet diisolir. Pembayaran tidak mengaktifkan kembali; aktifkan
                            kembali dari halaman ini.
                        </p>
                        <Input
                            label="Alasan"
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            required
                        />
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setActionModal(null)}
                            >
                                Batal
                            </Button>
                            <Button type="submit" variant="danger" loading={processing}>
                                Tangguhkan
                            </Button>
                        </div>
                    </form>
                </Modal>

                {changeOpen ? (
                    <ChangePackageModal
                        subscription={s}
                        packageOptions={packageOptions}
                        onClose={() => setChangeOpen(false)}
                    />
                ) : null}
            </div>
        </AdminLayout>
    );
}
