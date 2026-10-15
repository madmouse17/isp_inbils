import { BarChartLazy, LineChartLazy } from '@/Components/ui/ChartLazy';
import { useCompany } from '@/hooks/useCompany';
import { todayInZone } from '@/lib/dateRange';
import type { SalesChartData } from '@/types';
import { markRunning, periodUnit, topRows } from './chartData';
import { ReportChartCard } from './ReportChartCard';

const MAX_ACQUIRERS = 10;

export function SalesCharts({ charts }: { charts: SalesChartData }) {
    const company = useCompany();
    const unit = periodUnit(charts.series);
    const customers = topRows(
        charts.acquirers.map(({ name, customers_acquired }) => ({
            name,
            value: customers_acquired,
        })),
        MAX_ACQUIRERS,
    );
    const collected = topRows(
        charts.acquirers.map(({ name, collected }) => ({ name, value: collected })),
        MAX_ACQUIRERS,
    );

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <ReportChartCard title={`Pelanggan baru per ${unit}`} className="lg:col-span-2">
                <LineChartLazy
                    data={markRunning(charts.series, todayInZone(company?.timezone))}
                    xKey="label"
                    lines={[{ key: 'new_customers', name: 'Pelanggan baru' }]}
                    ariaLabel={`Grafik garis pelanggan baru per ${unit}`}
                />
            </ReportChartCard>
            <ReportChartCard title="Pelanggan baru per petugas penjualan">
                <BarChartLazy
                    data={customers}
                    xKey="name"
                    bars={[{ key: 'value', name: 'Pelanggan baru' }]}
                    layout="vertical"
                    ariaLabel="Grafik batang pelanggan baru per petugas penjualan"
                />
            </ReportChartCard>
            <ReportChartCard
                title="Pembayaran diterima per petugas penjualan"
                description="Semua pembayaran yang tidak dibatalkan, menurut tanggal bayar."
            >
                <BarChartLazy
                    data={collected}
                    xKey="name"
                    bars={[{ key: 'value', name: 'Pembayaran diterima' }]}
                    layout="vertical"
                    valueFormat="currency"
                    ariaLabel="Grafik batang pembayaran diterima per petugas penjualan"
                />
            </ReportChartCard>
        </div>
    );
}
