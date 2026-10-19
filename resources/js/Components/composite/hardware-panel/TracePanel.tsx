import { useId, useMemo } from 'react';
import { Link } from '@inertiajs/react';
import { User } from 'lucide-react';
import { NETWORK_ASSET_TYPE, labelOf } from '@/lib/statuses';
import type { TracePath } from '@/types/hardware-panel';
import { MiniPanel, PanelDefs, STATE_CLASS } from './PanelGlyphs';
import { PanelLegend } from './PanelLegend';
import { TRACE, layoutTrace, pathStateKey } from './panelGeometry.helpers';

export interface TracePanelProps {
    /** A path with at least one hop. */
    path: TracePath;
    /** Accessible name of the chain, e.g. "Jalur fiber SUB-0012". */
    label: string;
}

/**
 * The fibre path of one subscription: a card per hop, OLT first and the customer last, each with
 * the faceplate of the device and the lit port ringed, joined by a line in the state colour of the
 * drop. Every hop that is an asset is a link to its page, in tab order from left to right.
 */
export function TracePanel({ path, label }: TracePanelProps) {
    const uid = useId().replace(/:/g, '');
    const trace = useMemo(() => layoutTrace(path.hops), [path.hops]);
    const state = pathStateKey(path.state, path.online);
    const areaW = TRACE.hopW - 2 * TRACE.pad;

    return (
        <div className="space-y-3">
            <PanelLegend uid={uid} items={[{ key: state }]} online={path.online} />
            <div className="overflow-x-auto p-0.5">
                <div className="relative" style={{ width: trace.width, height: trace.height }}>
                    <svg
                        width={trace.width}
                        height={trace.height}
                        viewBox={`0 0 ${trace.width} ${trace.height}`}
                        aria-hidden="true"
                        focusable="false"
                        className="block max-w-none"
                    >
                        <PanelDefs uid={uid} />
                        {trace.hops.map((hop, n) => {
                            const source = path.hops[n];
                            return (
                                <g key={hop.x}>
                                    <rect
                                        x={hop.card.x + 0.5}
                                        y={0.5}
                                        width={hop.card.w - 1}
                                        height={hop.card.h - 1}
                                        rx={8}
                                        className="fill-card stroke-input"
                                    />
                                    {hop.geometry ? (
                                        <MiniPanel
                                            uid={uid}
                                            geometry={hop.geometry}
                                            x={hop.origin.x}
                                            y={hop.origin.y}
                                            scale={hop.scale}
                                            lit={
                                                source.lit
                                                    ? {
                                                          key: `${source.lit.group}:${source.lit.number}`,
                                                          state,
                                                      }
                                                    : null
                                            }
                                        />
                                    ) : (
                                        <rect
                                            x={hop.area.x}
                                            y={hop.area.y}
                                            width={hop.area.w}
                                            height={hop.area.h}
                                            rx={6}
                                            className="fill-muted"
                                        />
                                    )}
                                </g>
                            );
                        })}
                        {trace.hops.slice(1).map((hop, n) => {
                            const from = trace.hops[n];
                            // A hop without a lit port is joined at the middle of its card edge.
                            const start = from.litCentre ?? {
                                x: from.card.x + from.card.w,
                                y: from.area.y + from.area.h / 2,
                            };
                            const end = hop.litCentre ?? {
                                x: hop.card.x,
                                y: hop.area.y + hop.area.h / 2,
                            };
                            const jog = from.card.x + from.card.w + TRACE.gap / 2;
                            const d = `M${start.x} ${start.y}H${jog}V${end.y}H${end.x}`;

                            return (
                                <g
                                    key={hop.x}
                                    fill="none"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                >
                                    <path d={d} strokeWidth={5} className="stroke-card" />
                                    <path
                                        d={d}
                                        strokeWidth={2.5}
                                        className={`stroke-current ${STATE_CLASS[state]}`}
                                        style={{ strokeDasharray: 'var(--dash, none)' }}
                                    />
                                </g>
                            );
                        })}
                    </svg>
                    <ol aria-label={label} className="absolute inset-0 m-0 list-none p-0">
                        {trace.hops.map((hop, n) => {
                            const source = path.hops[n];
                            const asset = source.asset;
                            const lines = (
                                <>
                                    <span className="block truncate text-sm font-semibold text-foreground">
                                        {asset ? asset.code : 'Pelanggan'}
                                    </span>
                                    <span className="mt-auto block truncate pb-1.5 text-xs text-muted-foreground">
                                        {source.label}
                                    </span>
                                </>
                            );

                            return (
                                <li
                                    key={hop.x}
                                    className="absolute"
                                    style={{
                                        left: hop.card.x,
                                        top: 0,
                                        width: hop.card.w,
                                        height: hop.card.h,
                                    }}
                                >
                                    {hop.geometry === null ? (
                                        <span
                                            aria-hidden="true"
                                            className="absolute flex items-center justify-center text-xs text-muted-foreground"
                                            style={{
                                                left: TRACE.pad,
                                                top: hop.area.y,
                                                width: areaW,
                                                height: hop.area.h,
                                            }}
                                        >
                                            {asset ? (
                                                labelOf(NETWORK_ASSET_TYPE, asset.type)
                                            ) : (
                                                <User className="size-5" />
                                            )}
                                        </span>
                                    ) : null}
                                    {asset ? (
                                        <Link
                                            href={route('admin.network-assets.show', asset.id)}
                                            aria-label={
                                                source.lit
                                                    ? `${asset.code}, port ${source.label}`
                                                    : `${asset.code}, ${source.label}`
                                            }
                                            className="flex size-full flex-col rounded-lg px-2 pt-1.5 hover:bg-foreground/5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden focus-visible:ring-inset"
                                        >
                                            {lines}
                                        </Link>
                                    ) : (
                                        <div className="flex size-full flex-col px-2 pt-1.5">
                                            {lines}
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                </div>
            </div>
        </div>
    );
}
