import type { FormEvent } from 'react';
import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
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
import { useVisitPending } from '@/hooks/useVisitPending';
import { NETWORK_ASSET_STATUS, NETWORK_ASSET_TYPE } from '@/lib/statuses';
import type { NetworkAssetStatus, NetworkAssetType } from '@/types/network-asset';

interface TraceResult {
    id: number;
    code: string;
    name: string;
    asset_type: NetworkAssetType;
    serial_number?: string | null;
    status: NetworkAssetStatus;
    location?: { name: string; path?: string } | null;
    customer?: { name: string; code: string } | null;
    subscription?: { code: string } | null;
}

interface TraceProps extends Record<string, unknown> {
    results: TraceResult[];
    search: string;
    customer_id: string;
}

export default function Trace({ results, search }: TraceProps) {
    const [query, setQuery] = useState(search);
    const { pending, callbacks } = useVisitPending();

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
                                                <TD>{r.subscription?.code ?? <EmptyCell />}</TD>
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
