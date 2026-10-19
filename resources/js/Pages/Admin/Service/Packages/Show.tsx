import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { ACTIVE_STATE } from '@/lib/statuses';

interface PkgData {
    id: string;
    code: string;
    name: string;
    price_mrc: string;
    price_otc: string;
    contract_min_months: number | null;
    description: string | null;
    is_active: boolean;
    category: { id: string; code: string; name: string } | null;
    bandwidth_profile: { name: string } | null;
    speed_profile: { name: string } | null;
    sla_tier: { name: string } | null;
}

interface VersionRow {
    version: number;
    valid_from: string;
    price_mrc: string;
    price_otc: string;
    bandwidth_profile: { name: string } | null;
    speed_profile: { name: string } | null;
    sla_tier: { name: string } | null;
    category: { name: string } | null;
    subscriptions_count: number;
}

interface ShowProps extends Record<string, unknown> {
    servicePackage: { data: PkgData };
    versions: { data: VersionRow[] };
}

function DetailRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 break-words">{children}</dd>
        </>
    );
}

export default function Show({ servicePackage, versions }: ShowProps) {
    const p = servicePackage.data;

    return (
        <AdminLayout title={`Paket Layanan ${p.code}`}>
            <div className="space-y-6">
                <PageHeader
                    title={p.name}
                    subtitle={`Kode ${p.code}`}
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.service-packages.index')}>Kembali</Link>
                            </Button>
                            <Button asChild variant="primary" size="sm">
                                <Link href={route('admin.service-packages.edit', p.id)}>Ubah</Link>
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Rincian paket</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-3 text-sm">
                                <DetailRow label="Kategori">
                                    {p.category?.name ?? <EmptyCell />}
                                </DetailRow>
                                <DetailRow label="Profil bandwidth">
                                    {p.bandwidth_profile?.name ?? <EmptyCell />}
                                </DetailRow>
                                <DetailRow label="Profil kecepatan">
                                    {p.speed_profile?.name ?? <EmptyCell />}
                                </DetailRow>
                                <DetailRow label="Tingkat SLA">
                                    {p.sla_tier?.name ?? <EmptyCell />}
                                </DetailRow>
                                <DetailRow label="Masa kontrak minimum">
                                    {p.contract_min_months ? (
                                        `${p.contract_min_months} bulan`
                                    ) : (
                                        <EmptyCell />
                                    )}
                                </DetailRow>
                                {p.description ? (
                                    <DetailRow label="Deskripsi">{p.description}</DetailRow>
                                ) : null}
                            </dl>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Harga dan status</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-[max-content_1fr] items-center gap-x-6 gap-y-3 text-sm">
                                <DetailRow label="Harga bulanan">
                                    {formatMoney(p.price_mrc)}
                                </DetailRow>
                                <DetailRow label="Harga pemasangan">
                                    {formatMoney(p.price_otc)}
                                </DetailRow>
                                <DetailRow label="Status">
                                    <EnumBadge map={ACTIVE_STATE} value={p.is_active} />
                                </DetailRow>
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Versi paket</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="mb-3 text-sm text-muted-foreground">
                            Setiap perubahan biaya, profil, SLA, atau kategori membuat versi baru.
                            Langganan tetap memakai versi saat langganan dibuat atau dipindahkan.
                        </p>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Versi</TableHead>
                                    <TableHead>Berlaku sejak</TableHead>
                                    <TableHead className="text-right">Harga bulanan</TableHead>
                                    <TableHead className="text-right">Harga pemasangan</TableHead>
                                    <TableHead>Profil bandwidth</TableHead>
                                    <TableHead>Profil kecepatan</TableHead>
                                    <TableHead>Tingkat SLA</TableHead>
                                    <TableHead>Kategori</TableHead>
                                    <TableHead className="text-right">Langganan</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {versions.data.map((version, index) => (
                                    <TableRow key={version.version}>
                                        <TableCell className="whitespace-nowrap tabular-nums">
                                            <span className="font-medium">{version.version}</span>
                                            {index === 0 ? (
                                                <Badge variant="brand" className="ml-2">
                                                    Terbaru
                                                </Badge>
                                            ) : null}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            {formatDate(version.valid_from)}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {formatMoney(version.price_mrc)}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {formatMoney(version.price_otc)}
                                        </TableCell>
                                        <TableCell>
                                            {version.bandwidth_profile?.name ?? <EmptyCell />}
                                        </TableCell>
                                        <TableCell>
                                            {version.speed_profile?.name ?? <EmptyCell />}
                                        </TableCell>
                                        <TableCell>
                                            {version.sla_tier?.name ?? <EmptyCell />}
                                        </TableCell>
                                        <TableCell>
                                            {version.category?.name ?? <EmptyCell />}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatNumber(version.subscriptions_count)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
