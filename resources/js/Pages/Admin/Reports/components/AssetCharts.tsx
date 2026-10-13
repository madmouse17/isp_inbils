import { BarChartLazy, DonutChartLazy } from '@/Components/ui/ChartLazy';
import { codeLabel, countRows, topRows } from './chartData';
import { ReportChartCard } from './ReportChartCard';

interface AssetChartsProps {
    statusDistribution: Record<string, number> | undefined;
    byLocation: Record<string, number> | undefined;
}

export function AssetCharts({ statusDistribution, byLocation }: AssetChartsProps) {
    return (
        <div className="grid gap-4 lg:grid-cols-3">
            <ReportChartCard
                title="Aset jaringan menurut status"
                description="Posisi saat ini menurut jenis aset yang dipilih; periode tidak berlaku."
            >
                <DonutChartLazy
                    data={topRows(countRows(statusDistribution, codeLabel), 10)}
                    ariaLabel="Diagram donat jumlah aset jaringan menurut status"
                />
            </ReportChartCard>
            <ReportChartCard
                title="Aset jaringan per lokasi"
                description="Posisi saat ini; periode tidak berlaku."
                className="lg:col-span-2"
            >
                <BarChartLazy
                    data={topRows(countRows(byLocation), 10)}
                    xKey="name"
                    bars={[{ key: 'value', name: 'Aset' }]}
                    layout="vertical"
                    ariaLabel="Grafik batang jumlah aset jaringan per lokasi"
                />
            </ReportChartCard>
        </div>
    );
}
