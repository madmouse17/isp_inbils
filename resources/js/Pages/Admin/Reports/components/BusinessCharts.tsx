import { DonutChartLazy, LineChartLazy } from '@/Components/ui/ChartLazy';
import type { BusinessChartData } from '@/types';
import { codeLabel, periodUnit } from './chartData';
import { ReportChartCard } from './ReportChartCard';

export function BusinessCharts({ charts }: { charts: BusinessChartData }) {
    const unit = periodUnit(charts.series);

    return (
        <div className="grid gap-4 lg:grid-cols-3">
            <ReportChartCard
                title={`Pendapatan terbayar dan MRR per ${unit}`}
                description={`Pendapatan menurut tanggal terbit tagihan lunas. MRR adalah posisi di akhir setiap ${unit}.`}
                className="lg:col-span-2"
            >
                <LineChartLazy
                    data={charts.series.points}
                    xKey="label"
                    lines={[
                        { key: 'revenue_paid', name: 'Pendapatan terbayar' },
                        { key: 'mrr', name: 'MRR (akhir periode)' },
                    ]}
                    valueFormat="currency"
                    ariaLabel={`Grafik garis pendapatan terbayar dan MRR per ${unit}`}
                />
            </ReportChartCard>
            <ReportChartCard
                title="Tagihan menurut status"
                description="Menurut tanggal terbit tagihan."
            >
                <DonutChartLazy
                    data={charts.invoice_status.map(({ status, count }) => ({
                        name: codeLabel(status),
                        value: count,
                    }))}
                    ariaLabel="Diagram donat jumlah tagihan menurut status"
                />
            </ReportChartCard>
        </div>
    );
}
