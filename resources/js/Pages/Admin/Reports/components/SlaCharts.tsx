import { BarChartLazy, DonutChartLazy } from '@/Components/ui/ChartLazy';
import type { SlaChartData } from '@/types';
import { ReportChartCard } from './ReportChartCard';

interface SlaChartsProps {
    charts: SlaChartData;
    byCategory: Array<{ name: string; total: number; compliant: number }>;
}

export function SlaCharts({ charts, byCategory }: SlaChartsProps) {
    const { compliant, breached } = charts.compliance;

    return (
        <div className="grid gap-4 lg:grid-cols-3">
            <ReportChartCard
                title="Tiket selesai menurut SLA"
                description="Diselesaikan sebelum atau sesudah batas SLA."
            >
                <DonutChartLazy
                    data={[
                        { name: 'Sesuai SLA', value: compliant },
                        { name: 'Melewati SLA', value: breached },
                    ]}
                    ariaLabel="Diagram donat tiket selesai yang sesuai dan yang melewati SLA"
                />
            </ReportChartCard>
            <ReportChartCard title="Tiket selesai per kategori" className="lg:col-span-2">
                <BarChartLazy
                    data={byCategory}
                    xKey="name"
                    bars={[
                        { key: 'total', name: 'Total selesai' },
                        { key: 'compliant', name: 'Sesuai SLA' },
                    ]}
                    layout="vertical"
                    ariaLabel="Grafik batang tiket selesai dan yang sesuai SLA per kategori"
                />
            </ReportChartCard>
        </div>
    );
}
