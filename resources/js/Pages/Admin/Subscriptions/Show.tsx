import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyCell,
    EnumBadge,
    Input,
    Modal,
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

interface ShowProps extends Record<string, unknown> {
    subscription: { data: ServiceSubscription };
    ipAddresses: SubscriptionIpAddress[];
    /** Present only for holders of ipam.assign. */
    staticPools?: StaticPool[];
    dropPort: DropPort | null;
    /** Free ODP ports, found by typing an ODP code; odn.assign only. */
    dropPortOptions?: DropPortOption[];
    can: { assignIp: boolean; assignDrop: boolean; block: boolean };
}

function DetailRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 break-words">{children}</dd>
        </>
    );
}

export default function Show({
    subscription,
    ipAddresses,
    staticPools,
    dropPort,
    dropPortOptions,
    can: { block: canBlock, ...assignable },
}: ShowProps) {
    const s = subscription.data;
    const can = usePermission();
    const [actionModal, setActionModal] = useState<'suspend' | null>(null);
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
                                <DetailRow label="Paket">
                                    {s.package?.name ?? `#${s.service_package_id}`}
                                </DetailRow>
                                <DetailRow label="Kategori paket">
                                    {s.package?.category?.name ?? <EmptyCell />}
                                </DetailRow>
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
                    description="Layanan yang ditangguhkan dipulihkan kembali."
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
            </div>
        </AdminLayout>
    );
}
