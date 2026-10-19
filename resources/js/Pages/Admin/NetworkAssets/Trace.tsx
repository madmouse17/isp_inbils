import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
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
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { LoadingState, PageHeader } from '@/Components/composite';
import { TracePanelLazy } from '@/Components/composite/hardware-panel/HardwarePanelLazy';
import { usePermission } from '@/hooks/usePermission';
import { useVisitPending } from '@/hooks/useVisitPending';
import { NETWORK_ASSET_STATUS, NETWORK_ASSET_TYPE } from '@/lib/statuses';
import type { TracePath } from '@/types/hardware-panel';
import type { NetworkAssetStatus, NetworkAssetType } from '@/types/network-asset';

interface TraceResult {
    id: string;
    code: string;
    name: string;
    asset_type: NetworkAssetType;
    serial_number?: string | null;
    status: NetworkAssetStatus;
    location?: { name: string; path?: string } | null;
    customer?: { name: string; code: string } | null;
    subscription?: { id: string; code: string } | null;
}

interface TraceProps extends Record<string, unknown> {
    results: TraceResult[];
    search: string;
    customer_id: string;
    /** The fibre path of `?path_subscription=`; sent only to users who may see the ODN register. */
    tracePath?: TracePath | null;
}

/** The subscription code of a result and, when the user may read the ODN register, a button that draws its path. */
function SubscriptionCell({
    subscription,
    busy,
    onShow,
}: {
    subscription: { id: string; code: string };
    busy: boolean;
    onShow: ((subscriptionId: string) => void) | null;
}) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            <span>{subscription.code}</span>
            {onShow ? (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    loading={busy}
                    aria-label={`Jalur fiber ${subscription.code}`}
                    onClick={() => onShow(subscription.id)}
                >
                    Jalur fiber
                </Button>
            ) : null}
        </div>
    );
}

export default function Trace({ results, search, tracePath }: TraceProps) {
    const [query, setQuery] = useState(search);
    const { pending, callbacks } = useVisitPending();
    const can = usePermission();
    const [tracing, setTracing] = useState<string | null>(null);

    const showPath = (subscriptionId: string) => {
        setTracing(subscriptionId);
        // A reload keeps the page state and the scroll position by itself.
        router.reload({
            only: ['tracePath'],
            data: { path_subscription: subscriptionId },
            onFinish: () => setTracing(null),
        });
    };

    // The path answers a click that may be far down the table: bring its heading into view.
    useEffect(() => {
        if (tracePath) document.getElementById('trace-path-heading')?.focus();
    }, [tracePath]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            route('admin.network-assets.trace'),
            { search: query },
            { preserveState: true, ...callbacks },
        );
    };

    return (
        <AdminLayout title="Lacak Aset">
            <div className="space-y-6">
                <PageHeader
                    title="Lacak Aset"
                    subtitle="Cari aset berdasarkan nomor seri, alamat MAC, alamat IP, atau kode."
                />
                {tracePath ? (
                    <Card>
                        <CardHeader>
                            <CardTitle id="trace-path-heading" tabIndex={-1}>
                                Jalur fiber {tracePath.subscription.code}
                            </CardTitle>
                            <p className="text-sm text-muted-foreground">
                                {tracePath.subscription.customer}
                            </p>
                        </CardHeader>
                        <CardContent>
                            {tracePath.hops.length === 0 ? (
                                <div className="space-y-3 text-sm">
                                    <p className="text-muted-foreground">
                                        Belum ada port drop tercatat untuk{' '}
                                        {tracePath.subscription.code}. Atur di halaman langganan.
                                    </p>
                                    {can('customer.subscription.view') ? (
                                        <Button asChild variant="outline" size="sm">
                                            <Link
                                                href={route(
                                                    'admin.subscriptions.show',
                                                    tracePath.subscription.id,
                                                )}
                                            >
                                                Buka langganan
                                            </Link>
                                        </Button>
                                    ) : null}
                                </div>
                            ) : (
                                <TracePanelLazy
                                    path={tracePath}
                                    label={`Jalur fiber ${tracePath.subscription.code}`}
                                />
                            )}
                        </CardContent>
                    </Card>
                ) : null}
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex gap-2">
                            <Input
                                label="Cari"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder="Nomor seri, MAC, IP, atau kode"
                            />
                            <div className="self-end">
                                <Button type="submit" loading={pending}>
                                    Lacak
                                </Button>
                            </div>
                        </form>
                        {pending ? (
                            <LoadingState variant="rows" />
                        ) : (
                            <Table>
                                <THead>
                                    <TR>
                                        <TH>Nama</TH>
                                        <TH>Kode</TH>
                                        <TH>Tipe</TH>
                                        <TH>Nomor seri</TH>
                                        <TH>Status</TH>
                                        <TH>Lokasi</TH>
                                        <TH>Jalur</TH>
                                        <TH>Pelanggan</TH>
                                        <TH>Langganan</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {results.length === 0 ? (
                                        <TR>
                                            <TD
                                                colSpan={9}
                                                className="py-10 text-center text-muted-foreground"
                                            >
                                                {search === ''
                                                    ? 'Masukkan nomor seri, MAC, IP, atau kode, lalu pilih Lacak.'
                                                    : `Tidak ada aset yang cocok dengan "${search}". Periksa penulisannya atau coba kata kunci lain.`}
                                            </TD>
                                        </TR>
                                    ) : (
                                        results.map((r) => (
                                            <TR key={r.id}>
                                                <TD>{r.name}</TD>
                                                <TD className="font-mono text-sm">{r.code}</TD>
                                                <TD>
                                                    <EnumBadge
                                                        map={NETWORK_ASSET_TYPE}
                                                        value={r.asset_type}
                                                    />
                                                </TD>
                                                <TD className="font-mono text-sm">
                                                    {r.serial_number ?? <EmptyCell />}
                                                </TD>
                                                <TD>
                                                    <EnumBadge
                                                        map={NETWORK_ASSET_STATUS}
                                                        value={r.status}
                                                    />
                                                </TD>
                                                <TD>{r.location?.name ?? <EmptyCell />}</TD>
                                                <TD>{r.location?.path ?? <EmptyCell />}</TD>
                                                <TD>{r.customer?.name ?? <EmptyCell />}</TD>
                                                <TD>
                                                    {r.subscription ? (
                                                        <SubscriptionCell
                                                            subscription={r.subscription}
                                                            busy={tracing === r.subscription.id}
                                                            onShow={
                                                                can('odn.view') ? showPath : null
                                                            }
                                                        />
                                                    ) : (
                                                        <EmptyCell />
                                                    )}
                                                </TD>
                                            </TR>
                                        ))
                                    )}
                                </TBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
