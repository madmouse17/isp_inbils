import { useId, useLayoutEffect, useMemo, useRef } from 'react';
import type { FocusEvent, KeyboardEvent } from 'react';
import { Plus } from 'lucide-react';
import type { PanelPayload, RackItem } from '@/types/hardware-panel';
import { MiniPanel, PanelDefs } from './PanelGlyphs';
import {
    RACK_BODY_WIDTH,
    RACK_FRAME,
    RACK_RAIL,
    UNIT_HEIGHT,
    layoutRack,
    layoutStatic,
    moveStop,
} from './panelGeometry.helpers';

export interface RackPanelProps {
    asset: PanelPayload['asset'];
    heightU: number;
    items: RackItem[];
    /** Accessible name of the rack, e.g. "Isi RAK-PUSAT". */
    label: string;
    /** An empty U was chosen; only passed when the rack is editable, and then every empty U is a button. */
    onSelectSlot?: (unit: number, trigger: HTMLElement) => void;
    onSelectItem?: (item: RackItem, trigger: HTMLElement) => void;
}

/** Width of the text zone of a bar; the faceplate is drawn to the right of it. */
const LABEL_W = 150;
const BAR_PAD = 4;

const BUTTON_RING =
    'rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden focus-visible:ring-inset';

/** One button of the rack: a free U, or a device; `unit` to `to` are the Us it stands on. */
type RackRow =
    | { type: 'slot'; unit: number; to: number; y: number }
    | { type: 'item'; n: number; unit: number; to: number; y: number };

/**
 * A rack at one pixel per unit, so a U is 44.45px high, a comfortable touch target. U1 is at the
 * bottom. Each mounted device is a labelled bar with its faceplate; when the rack is editable every
 * empty U is a button too. One tab stop, ArrowUp and ArrowDown move through the slots. When a write
 * turns the U the user opened into a device, or a device into free Us, the focus moves to the button
 * at that U instead of falling back to the page.
 */
export function RackPanel({
    asset,
    heightU,
    items,
    label,
    onSelectSlot,
    onSelectItem,
}: RackPanelProps) {
    const uid = useId().replace(/:/g, '');
    const hintId = `${uid}-hint`;
    const groupRef = useRef<HTMLDivElement>(null);
    // The button the user opened and the U it stands at; a write that replaces the button needs them.
    const opened = useRef<{ trigger: HTMLElement; unit: number } | null>(null);
    const rack = useMemo(() => layoutRack(heightU, items), [heightU, items]);
    const minis = useMemo(
        () =>
            rack.items.map((item) => (item.layout ? layoutStatic(item.layout, item.height) : null)),
        [rack],
    );
    const rows = useMemo(() => {
        const taken = new Set<number>();
        for (const item of rack.items) {
            for (let unit = item.unit; unit < item.unit + item.height; unit += 1) taken.add(unit);
        }
        const free = onSelectSlot ? rack.slots.filter((slot) => !taken.has(slot.unit)) : [];

        return [
            ...free.map((slot): RackRow => ({
                type: 'slot',
                unit: slot.unit,
                to: slot.unit,
                y: slot.y,
            })),
            ...rack.items.map((item, n): RackRow => ({
                type: 'item',
                n,
                unit: item.unit,
                to: item.unit + item.height - 1,
                y: item.y,
            })),
        ].sort((a, b) => a.y - b.y);
    }, [rack, onSelectSlot]);

    useLayoutEffect(() => {
        const group = groupRef.current;
        if (!group) return;

        // The button the user opened is gone: a device was mounted on its U, or the device it was is out of the
        // rack. The browser dropped the focus with it, so put it on the button at that U now. A control that
        // has focus keeps it.
        const used = opened.current;
        if (used && !used.trigger.isConnected) {
            opened.current = null;
            const next =
                group.children[
                    rows.findIndex((row) => row.unit <= used.unit && used.unit <= row.to)
                ];
            const active = document.activeElement;
            if ((active === null || active === document.body) && next instanceof HTMLElement)
                next.focus();
        }

        if (!group.querySelector('[tabindex="0"]')) moveStop(group, group.children[0]);
    }, [rows]);

    const onFocus = (event: FocusEvent<HTMLDivElement>) => {
        if (event.target instanceof HTMLButtonElement) moveStop(event.currentTarget, event.target);
    };
    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.altKey || event.ctrlKey || event.metaKey) return;
        const buttons = Array.from(event.currentTarget.children);
        const from = buttons.indexOf(event.target as Element);
        const to =
            event.key === 'ArrowDown'
                ? from + 1
                : event.key === 'ArrowUp'
                  ? from - 1
                  : event.key === 'Home'
                    ? 0
                    : event.key === 'End'
                      ? buttons.length - 1
                      : null;
        if (from < 0 || to === null) return;
        event.preventDefault();
        const next = buttons[Math.min(Math.max(to, 0), buttons.length - 1)];
        if (next instanceof HTMLElement) next.focus();
    };

    const bodyX = RACK_RAIL;
    const zoneX = bodyX + LABEL_W;
    const zoneW = RACK_BODY_WIDTH - LABEL_W - BAR_PAD * 2;

    return (
        <div data-rack-asset={asset.id} className="space-y-2">
            <div className="overflow-x-auto p-0.5">
                <div className="relative" style={{ width: rack.width, height: rack.height }}>
                    <svg
                        width={rack.width}
                        height={rack.height}
                        viewBox={`0 0 ${rack.width} ${rack.height}`}
                        aria-hidden="true"
                        focusable="false"
                        className="block max-w-none"
                    >
                        <PanelDefs uid={uid} />
                        <rect
                            x={0.5}
                            y={0.5}
                            width={rack.width - 1}
                            height={rack.height - 1}
                            rx={4}
                            className="fill-muted stroke-input"
                        />
                        <rect
                            x={bodyX}
                            y={RACK_FRAME}
                            width={RACK_BODY_WIDTH}
                            height={rack.height - 2 * RACK_FRAME}
                            className="fill-background"
                        />
                        <path
                            d={rack.slots
                                .slice(1)
                                .map((slot) => `M${bodyX} ${slot.y}H${bodyX + RACK_BODY_WIDTH}`)
                                .join('')}
                            strokeWidth={0.5}
                            className="fill-none stroke-border"
                        />
                        {rack.slots.map((slot) => (
                            <text
                                key={slot.unit}
                                x={RACK_RAIL / 2}
                                y={slot.y + UNIT_HEIGHT / 2}
                                textAnchor="middle"
                                dominantBaseline="central"
                                className="fill-muted-foreground text-xs tabular-nums"
                            >
                                {slot.unit}
                            </text>
                        ))}
                        {rack.items.map((item, n) => {
                            const mini = minis[n];
                            const zoneH = item.h - BAR_PAD * 2;
                            const scale = mini
                                ? Math.min(zoneW / mini.width, zoneH / mini.height, 1)
                                : 1;

                            return (
                                <g key={item.id}>
                                    <rect
                                        x={bodyX + 1}
                                        y={item.y + 1}
                                        width={RACK_BODY_WIDTH - 2}
                                        height={item.h - 2}
                                        rx={3}
                                        className="fill-muted stroke-input"
                                    />
                                    {mini ? (
                                        <MiniPanel
                                            uid={uid}
                                            geometry={mini}
                                            x={zoneX + (zoneW - mini.width * scale) / 2}
                                            y={item.y + BAR_PAD + (zoneH - mini.height * scale) / 2}
                                            scale={scale}
                                        />
                                    ) : null}
                                </g>
                            );
                        })}
                    </svg>
                    <div
                        ref={groupRef}
                        role="group"
                        aria-label={label}
                        aria-describedby={hintId}
                        className="absolute inset-0"
                        onFocus={onFocus}
                        onKeyDown={onKeyDown}
                    >
                        {rows.map((row) => {
                            if (row.type === 'slot') {
                                return (
                                    <button
                                        key={`u${row.unit}`}
                                        type="button"
                                        tabIndex={-1}
                                        aria-label={`U${row.unit} kosong, pasang perangkat`}
                                        className={`absolute flex items-center px-3 text-muted-foreground hover:bg-accent ${BUTTON_RING}`}
                                        style={{
                                            left: bodyX,
                                            top: row.y,
                                            width: RACK_BODY_WIDTH,
                                            height: UNIT_HEIGHT,
                                        }}
                                        onClick={(event) => {
                                            opened.current = {
                                                trigger: event.currentTarget,
                                                unit: row.unit,
                                            };
                                            onSelectSlot?.(row.unit, event.currentTarget);
                                        }}
                                    >
                                        <Plus className="size-4" aria-hidden="true" />
                                    </button>
                                );
                            }

                            const item = rack.items[row.n];
                            return (
                                <button
                                    key={item.id}
                                    type="button"
                                    tabIndex={-1}
                                    aria-label={`${item.code}, U${item.unit}, ${item.height}U`}
                                    className={`absolute flex flex-col items-start justify-center px-3 text-left hover:bg-foreground/5 ${BUTTON_RING}`}
                                    style={{
                                        left: bodyX + 1,
                                        top: item.y + 1,
                                        width: RACK_BODY_WIDTH - 2,
                                        height: item.h - 2,
                                    }}
                                    onClick={(event) => {
                                        opened.current = {
                                            trigger: event.currentTarget,
                                            unit: item.unit,
                                        };
                                        onSelectItem?.(item, event.currentTarget);
                                    }}
                                >
                                    <span className="block max-w-34 truncate text-sm font-semibold text-foreground">
                                        {item.code}
                                    </span>
                                    <span className="block max-w-34 truncate text-xs text-muted-foreground">
                                        {item.name}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>
            <p
                id={hintId}
                className="sr-only text-xs text-muted-foreground pointer-fine:not-sr-only"
            >
                Tombol panah atas dan bawah memindah slot · Enter membuka
            </p>
        </div>
    );
}
