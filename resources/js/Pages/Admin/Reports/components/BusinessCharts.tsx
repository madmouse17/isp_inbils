import { DonutChartLazy, LineChartLazy } from '@/Components/ui/ChartLazy';
import { useCompany } from '@/hooks/useCompany';
import { todayInZone } from '@/lib/dateRange';
import { INVOICE_STATUS } from '@/lib/statuses';
import type { BusinessChartData } from '@/types';
import { markRunning, periodUnit, statusSlices } from './chartData';
import { ReportChartCard } from './ReportChartCard';

export function BusinessCharts({ charts }: { charts: BusinessChartData }) {
    const company = useCompany();
    const unit = periodUnit(charts.series);
    const counts = Object.fromEntries(
        charts.invoice_status.map(({ status, count }) => [status, count]),
    );

    return (
        <div className="grid gap-4 lg:grid-cols-3">
            <ReportChartCard
                title={`Pendapatan terbayar dan MRR per ${unit}`}
                description={`Pendapatan menurut tanggal terbit tagihan lunas. MRR adalah posisi di akhir setiap ${unit}.`}
                className="lg:col-span-2"
            >
                <LineChartLazy
                    data={markRunning(charts.series, todayInZone(company?.timezone))}
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
                    data={statusSlices(counts, INVOICE_STATUS)}
                    ariaLabel="Diagram donat jumlah tagihan menurut status"
                />
            </ReportChartCard>
        </div>
    );
}
