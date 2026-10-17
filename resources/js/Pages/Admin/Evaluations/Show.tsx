import type { ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
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
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import { useCompany } from '@/hooks/useCompany';
import { formatDateTime, formatNumber } from '@/lib/format';
import { EVALUATION_REFERENCE_TYPE, labelOf } from '@/lib/statuses';

interface EvalData {
    id: string;
    reference_type: string;
    reference_id: string;
    score: string;
    customer_rating?: string | null;
    first_response_minutes?: number | null;
    resolution_minutes?: number | null;
    comment?: string | null;
    evaluated_at: string;
    employee?: { name: string } | null;
    evaluator?: { name: string } | null;
}

interface ShowProps extends Record<string, unknown> {
    evaluation: { data: EvalData };
    /** Code and title of the ticket or SPK evaluated; null when that record is gone. */
    referenceOption: { code: string; title: string } | null;
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium text-foreground">{children}</dd>
        </div>
    );
}

export default function Show({ evaluation, referenceOption }: ShowProps) {
    const ev = evaluation.data;
    const company = useCompany();
    const referenceCode = referenceOption?.code ?? `#${ev.reference_id}`;
    const referenceLabel = `${labelOf(EVALUATION_REFERENCE_TYPE, ev.reference_type)} ${referenceCode}`;

    return (
        <AdminLayout title="Detail Evaluasi">
            <div className="space-y-6">
                <PageHeader
                    title={`Evaluasi ${ev.employee?.name ?? ''}`.trim()}
                    subtitle={`${referenceLabel}, ${formatDateTime(ev.evaluated_at, company)}`}
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.evaluations.index')}>Kembali</Link>
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.get(route('admin.evaluations.edit', ev.id))}
                            >
                                Ubah
                            </Button>
                        </>
                    }
                />
                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Hasil penilaian</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-x-6 gap-y-4 text-sm md:grid-cols-2">
                                <Detail label="Karyawan">
                                    {ev.employee?.name ?? <EmptyCell />}
                                </Detail>
                                <Detail label="Penilai">
                                    {ev.evaluator?.name ?? <EmptyCell />}
                                </Detail>
                                <Detail label="Nilai">{ev.score}</Detail>
                                <Detail label="Penilaian pelanggan">
                                    {ev.customer_rating ?? <EmptyCell />}
                                </Detail>
                                <Detail label="Respons pertama">
                                    {ev.first_response_minutes == null ? (
                                        <EmptyCell />
                                    ) : (
                                        `${formatNumber(ev.first_response_minutes)} menit`
                                    )}
                                </Detail>
                                <Detail label="Penyelesaian">
                                    {ev.resolution_minutes == null ? (
                                        <EmptyCell />
                                    ) : (
                                        `${formatNumber(ev.resolution_minutes)} menit`
                                    )}
                                </Detail>
                                <div className="md:col-span-2">
                                    <dt className="text-muted-foreground">Komentar</dt>
                                    <dd className="font-medium whitespace-pre-wrap text-foreground">
                                        {ev.comment || <EmptyCell />}
                                    </dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Referensi</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-4 text-sm">
                                <Detail label="Jenis">
                                    <EnumBadge
                                        map={EVALUATION_REFERENCE_TYPE}
                                        value={ev.reference_type}
                                    />
                                </Detail>
                                <Detail label="Kode">{referenceCode}</Detail>
                                <Detail label="Judul">
                                    {referenceOption?.title ?? <EmptyCell />}
                                </Detail>
                                <Detail label="Tanggal evaluasi">
                                    {formatDateTime(ev.evaluated_at, company)}
                                </Detail>
                            </dl>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
