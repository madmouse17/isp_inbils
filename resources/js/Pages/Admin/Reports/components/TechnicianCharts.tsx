import { BarChartLazy, LineChartLazy } from '@/Components/ui/ChartLazy';
import { useCompany } from '@/hooks/useCompany';
import { todayInZone } from '@/lib/dateRange';
import { WORK_ORDER_TYPE, labelOf } from '@/lib/statuses';
import type { TechnicianChartData } from '@/types';
import { countRows, markRunning, periodUnit, topRows } from './chartData';
import { ReportChartCard } from './ReportChartCard';

interface TechnicianChartsProps {
    charts: TechnicianChartData;
    spkByType: Record<string, number> | undefined;
    ticketsByCategory: Record<string, number> | undefined;
}

export function TechnicianCharts({ charts, spkByType, ticketsByCategory }: TechnicianChartsProps) {
    const company = useCompany();
    const unit = periodUnit(charts.series);

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <ReportChartCard title={`SPK selesai per ${unit}`} className="lg:col-span-2">
                <LineChartLazy
                    data={markRunning(charts.series, todayInZone(company?.timezone))}
                    xKey="label"
                    lines={[{ key: 'spk_completed', name: 'SPK selesai' }]}
                    ariaLabel={`Grafik garis SPK selesai per ${unit}`}
                />
            </ReportChartCard>
            <ReportChartCard title="SPK selesai menurut jenis">
                <BarChartLazy
                    data={topRows(
                        countRows(spkByType, (code) => labelOf(WORK_ORDER_TYPE, code)),
                        10,
                    )}
                    xKey="name"
                    bars={[{ key: 'value', name: 'SPK selesai' }]}
                    layout="vertical"
                    ariaLabel="Grafik batang SPK selesai menurut jenis"
                />
            </ReportChartCard>
            <ReportChartCard title="Tiket selesai menurut kategori">
                <BarChartLazy
                    data={topRows(countRows(ticketsByCategory), 10)}
                    xKey="name"
                    bars={[{ key: 'value', name: 'Tiket selesai' }]}
                    layout="vertical"
                    ariaLabel="Grafik batang tiket selesai menurut kategori"
                />
            </ReportChartCard>
        </div>
    );
}
