import {
    Bar,
    BarChart as RechartsBarChart,
    CartesianGrid,
    Line,
    LineChart as RechartsLineChart,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { useCompany } from '@/hooks/useCompany';
import { formatNumber, formatRupiah } from '@/lib/format';

/** One plotted series: the data key it reads and the name the legend and tooltip show. */
export interface ChartSeries {
    key: string;
    name: string;
}

type ChartRow = Record<string, number | string>;
type ValueFormat = 'number' | 'currency';

interface ChartBaseProps {
    height?: number;
    /** What the chart shows, in a sentence; the table below it is the accessible equivalent. */
    ariaLabel: string;
    valueFormat?: ValueFormat;
}

export interface LineChartProps extends ChartBaseProps {
    data: ChartRow[];
    xKey: string;
    lines: ChartSeries[];
}

export interface BarChartProps extends ChartBaseProps {
    data: ChartRow[];
    xKey: string;
    bars: ChartSeries[];
    /** As in recharts: 'horizontal' (default) draws columns, 'vertical' draws rows with the names on the left. */
    layout?: 'vertical' | 'horizontal';
}

export interface DonutChartProps {
    /** `color` is a CSS colour (`toneColor('success')`) for a slice that means a state; absent, the slice takes the next `--chart-N`. */
    data: Array<{ name: string; value: number; color?: string }>;
    height?: number;
    ariaLabel: string;
}

/** Keep in step with ChartLazy.tsx, which sizes its skeleton before this module has loaded. */
const DEFAULT_HEIGHT = 280;
const COMPACT = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 });

const AXIS_TICK = { fill: 'hsl(var(--muted-foreground))', fontSize: 12 } as const;
const GRID_STROKE = 'hsl(var(--border))';
const TOOLTIP_STYLE = {
    backgroundColor: 'hsl(var(--popover))',
    border: '1px solid hsl(var(--border))',
    borderRadius: 'calc(var(--radius) - 2px)',
    color: 'hsl(var(--popover-foreground))',
    fontSize: 12,
} as const;
// recharts paints each tooltip row in its series colour; text stays in the foreground colour instead.
const TOOLTIP_ITEM_STYLE = { color: 'hsl(var(--popover-foreground))' } as const;
const TOOLTIP_LABEL_STYLE = { color: 'hsl(var(--foreground))', fontWeight: 600 } as const;

/** --chart-1..6 live in app.css with light and dark values. */
const seriesColor = (index: number) => `var(--chart-${(index % 6) + 1})`;

/** The API may send decimals as strings. */
const toNumber = (value: unknown): number => {
    const number = typeof value === 'number' ? value : Number(value);

    return Number.isFinite(number) ? number : 0;
};

function hasPlottedValue(rows: ChartRow[], keys: string[]): boolean {
    return rows.some((row) => keys.some((key) => toNumber(row[key]) !== 0));
}

function useValueFormat(valueFormat: ValueFormat) {
    const company = useCompany();

    return {
        /** Exact, for tooltips and the donut legend. */
        full: (value: number) =>
            valueFormat === 'currency' ? formatRupiah(value, company) : formatNumber(value),
        /** Short (12,5 jt), for axis ticks. */
        tick: (value: number) => COMPACT.format(value),
    };
}

function ChartEmpty({ height }: { height: number }) {
    return (
        <div
            className="flex items-center justify-center rounded-md border border-dashed bg-muted/30 px-4 text-center text-sm text-muted-foreground"
            style={{ height }}
        >
            Tidak ada data pada periode ini
        </div>
    );
}

function ChartLegend({
    items,
}: {
    items: Array<{ name: string; value?: string; color?: string }>;
}) {
    return (
        <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
            {items.map((item, index) => (
                <li key={item.name} className="flex items-center gap-1.5">
                    <span
                        aria-hidden="true"
                        className="h-2.5 w-2.5 shrink-0 rounded-sm"
                        style={{ backgroundColor: item.color ?? seriesColor(index) }}
                    />
                    <span className="text-foreground">{item.name}</span>
                    {item.value ? (
                        <span className="text-muted-foreground tabular-nums">{item.value}</span>
                    ) : null}
                </li>
            ))}
        </ul>
    );
}

/** Series values as numbers: recharts would read a decimal string as a category. */
function toNumericRows(rows: ChartRow[], keys: string[]): ChartRow[] {
    return rows.map((row) => ({
        ...row,
        ...Object.fromEntries(keys.map((key) => [key, toNumber(row[key])])),
    }));
}

export function LineChart({
    data,
    xKey,
    lines,
    height = DEFAULT_HEIGHT,
    ariaLabel,
    valueFormat = 'number',
}: LineChartProps) {
    const format = useValueFormat(valueFormat);
    const keys = lines.map((line) => line.key);

    if (!hasPlottedValue(data, keys)) return <ChartEmpty height={height} />;

    return (
        <div>
            <ResponsiveContainer width="100%" height={height}>
                <RechartsLineChart
                    data={toNumericRows(data, keys)}
                    accessibilityLayer
                    aria-label={ariaLabel}
                    margin={{ top: 8, right: 12, bottom: 0, left: 0 }}
                >
                    <CartesianGrid stroke={GRID_STROKE} strokeDasharray="3 3" vertical={false} />
                    <XAxis
                        dataKey={xKey}
                        tick={AXIS_TICK}
                        tickLine={false}
                        axisLine={{ stroke: GRID_STROKE }}
                        interval="preserveStartEnd"
                        minTickGap={16}
                    />
                    <YAxis
                        width="auto"
                        tick={AXIS_TICK}
                        tickLine={false}
                        axisLine={false}
                        allowDecimals={false}
                        tickFormatter={(value: number) => format.tick(value)}
                    />
                    <Tooltip
                        cursor={{ stroke: GRID_STROKE }}
                        contentStyle={TOOLTIP_STYLE}
                        itemStyle={TOOLTIP_ITEM_STYLE}
                        labelStyle={TOOLTIP_LABEL_STYLE}
                        itemSorter={(item) => keys.indexOf(String(item.dataKey))}
                        formatter={(value) => format.full(toNumber(value))}
                    />
                    {lines.map((line, index) => (
                        <Line
                            key={line.key}
                            type="monotone"
                            dataKey={line.key}
                            name={line.name}
                            stroke={seriesColor(index)}
                            strokeWidth={2}
                            dot={data.length <= 31 ? { r: 2.5, fill: seriesColor(index) } : false}
                            activeDot={{ r: 4 }}
                            isAnimationActive={false}
                        />
                    ))}
                </RechartsLineChart>
            </ResponsiveContainer>
            {lines.length > 1 ? <ChartLegend items={lines} /> : null}
        </div>
    );
}

const truncate = (label: string, max: number) =>
    label.length > max ? `${label.slice(0, max - 1)}…` : label;

export function BarChart({
    data,
    xKey,
    bars,
    height,
    ariaLabel,
    valueFormat = 'number',
    layout = 'horizontal',
}: BarChartProps) {
    const format = useValueFormat(valueFormat);
    const keys = bars.map((bar) => bar.key);
    const rows = layout === 'vertical';
    // Rows need room for every name; columns do not.
    const chartHeight =
        height ?? (rows ? Math.max(DEFAULT_HEIGHT, data.length * 36 + 56) : DEFAULT_HEIGHT);

    if (!hasPlottedValue(data, keys)) return <ChartEmpty height={chartHeight} />;

    const categoryAxis = {
        dataKey: xKey,
        type: 'category',
        tick: AXIS_TICK,
        tickLine: false,
        tickFormatter: (value: string) => truncate(String(value), rows ? 22 : 12),
        interval: 0,
    } as const;
    const valueAxis = {
        type: 'number',
        tick: AXIS_TICK,
        tickLine: false,
        allowDecimals: false,
        tickFormatter: (value: number) => format.tick(value),
    } as const;

    return (
        <div>
            <ResponsiveContainer width="100%" height={chartHeight}>
                <RechartsBarChart
                    data={toNumericRows(data, keys)}
                    layout={layout}
                    accessibilityLayer
                    aria-label={ariaLabel}
                    margin={{ top: 8, right: 12, bottom: 0, left: 0 }}
                >
                    <CartesianGrid
                        stroke={GRID_STROKE}
                        strokeDasharray="3 3"
                        horizontal={!rows}
                        vertical={rows}
                    />
                    {rows ? (
                        <>
                            <XAxis {...valueAxis} axisLine={{ stroke: GRID_STROKE }} />
                            <YAxis {...categoryAxis} width="auto" axisLine={false} />
                        </>
                    ) : (
                        <>
                            <XAxis {...categoryAxis} axisLine={{ stroke: GRID_STROKE }} />
                            <YAxis {...valueAxis} width="auto" axisLine={false} />
                        </>
                    )}
                    <Tooltip
                        cursor={{ fill: 'hsl(var(--muted))', opacity: 0.5 }}
                        contentStyle={TOOLTIP_STYLE}
                        itemStyle={TOOLTIP_ITEM_STYLE}
                        labelStyle={TOOLTIP_LABEL_STYLE}
                        itemSorter={(item) => keys.indexOf(String(item.dataKey))}
                        formatter={(value) => format.full(toNumber(value))}
                    />
                    {bars.map((bar, index) => (
                        <Bar
                            key={bar.key}
                            dataKey={bar.key}
                            name={bar.name}
                            fill={seriesColor(index)}
                            radius={rows ? [0, 4, 4, 0] : [4, 4, 0, 0]}
                            maxBarSize={36}
                            isAnimationActive={false}
                        />
                    ))}
                </RechartsBarChart>
            </ResponsiveContainer>
            {bars.length > 1 ? <ChartLegend items={bars} /> : null}
        </div>
    );
}

export function DonutChart({ data, height = DEFAULT_HEIGHT, ariaLabel }: DonutChartProps) {
    const format = useValueFormat('number');
    const total = data.reduce((sum, slice) => sum + toNumber(slice.value), 0);

    if (total === 0) return <ChartEmpty height={height} />;

    // The colour follows the slice's place in `data`, so a slice keeps its colour when a sibling is 0.
    const slices = data
        .map((slice, index) => ({
            name: slice.name,
            value: toNumber(slice.value),
            fill: slice.color ?? seriesColor(index),
        }))
        .filter((slice) => slice.value > 0);

    return (
        <div>
            <ResponsiveContainer width="100%" height={height}>
                <PieChart accessibilityLayer aria-label={ariaLabel}>
                    <Pie
                        data={slices}
                        dataKey="value"
                        nameKey="name"
                        innerRadius="62%"
                        outerRadius="88%"
                        paddingAngle={slices.length > 1 ? 2 : 0}
                        stroke="hsl(var(--card))"
                        strokeWidth={2}
                        isAnimationActive={false}
                    />
                    <Tooltip
                        contentStyle={TOOLTIP_STYLE}
                        itemStyle={TOOLTIP_ITEM_STYLE}
                        labelStyle={TOOLTIP_LABEL_STYLE}
                        formatter={(value) => format.full(toNumber(value))}
                    />
                </PieChart>
            </ResponsiveContainer>
            <ChartLegend
                items={data.map((slice) => ({
                    name: slice.name,
                    color: slice.color,
                    value: `${format.full(toNumber(slice.value))} (${Math.round((toNumber(slice.value) / total) * 100)}%)`,
                }))}
            />
        </div>
    );
}
