import { cn } from '@/lib/utils';
import type { MetricDefinitionData, MetricUnit } from '@/types';

const UNIT_LABELS: Record<MetricUnit, string> = {
    currency: 'Nilai uang',
    count: 'Jumlah',
    percent: 'Persen',
    minutes: 'Menit',
    rating: 'Penilaian',
    quantity: 'Kuantitas',
    breakdown: 'Rincian (jumlah per kelompok)',
    records: 'Catatan (baris buku besar)',
};

interface MetricDefinitionDetailsProps {
    definition: MetricDefinitionData;
    className?: string;
}

export function MetricDefinitionDetails({ definition, className }: MetricDefinitionDetailsProps) {
    const dateBasis =
        definition.date_basis === 'snapshot'
            ? 'Posisi saat ini. Mengikuti data terkini; filter Dari/Sampai tidak mengubahnya.'
            : `Per periode. Menghitung data yang ${definition.date_column ?? 'tanggalnya'} jatuh di dalam rentang Dari/Sampai.`;

    return (
        <dl className={cn('space-y-3 min-w-0 text-sm', className)}>
            <div className="min-w-0">
                <dt className="font-medium text-foreground">Rumus</dt>
                <dd className="mt-0.5 min-w-0 wrap-break-word text-muted-foreground">
                    {definition.formula}
                </dd>
            </div>
            <div className="min-w-0">
                <dt className="font-medium text-foreground">Satuan</dt>
                <dd className="mt-0.5 min-w-0 wrap-break-word text-muted-foreground">
                    {UNIT_LABELS[definition.unit]}
                </dd>
            </div>
            <div className="min-w-0">
                <dt className="font-medium text-foreground">Dasar tanggal</dt>
                <dd className="mt-0.5 min-w-0 wrap-break-word text-muted-foreground">
                    {dateBasis}
                </dd>
            </div>
            {definition.statuses.length > 0 && (
                <div className="min-w-0">
                    <dt className="font-medium text-foreground">Status</dt>
                    <dd className="mt-0.5 min-w-0 wrap-break-word text-muted-foreground">
                        {definition.statuses.join(', ')}
                    </dd>
                </div>
            )}
            <div className="min-w-0">
                <dt className="font-medium text-foreground">Sumber data</dt>
                <dd className="mt-0.5 min-w-0 text-muted-foreground">
                    <ul className="space-y-1">
                        {definition.sources.map((source) => (
                            <li key={source} className="min-w-0 wrap-break-word">
                                <code className="font-mono text-xs wrap-break-word text-foreground">
                                    {source}
                                </code>
                            </li>
                        ))}
                    </ul>
                </dd>
            </div>
            <div className="min-w-0">
                <dt className="font-medium text-foreground">Cakupan</dt>
                <dd className="mt-0.5 min-w-0 wrap-break-word text-muted-foreground">
                    {definition.scope}
                </dd>
            </div>
            {definition.caveats.length > 0 && (
                <div className="min-w-0">
                    <dt className="font-medium text-foreground">Catatan penting</dt>
                    <dd className="mt-0.5 min-w-0 text-muted-foreground">
                        <ul className="space-y-1 list-disc pl-5">
                            {definition.caveats.map((caveat) => (
                                <li key={caveat} className="min-w-0 wrap-break-word">
                                    {caveat}
                                </li>
                            ))}
                        </ul>
                    </dd>
                </div>
            )}
        </dl>
    );
}
