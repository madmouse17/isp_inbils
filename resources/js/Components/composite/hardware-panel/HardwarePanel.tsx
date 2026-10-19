import { memo, useCallback, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import type { CSSProperties, FocusEvent, KeyboardEvent, MouseEvent, PointerEvent } from 'react';
import { PORT_STATE } from '@/lib/statuses/network';
import type { PanelGroup, PanelPayload, PanelPort, PanelTemplate } from '@/types/hardware-panel';
import { Chassis, FixtureGlyphs, PanelDefs, PortGlyphs } from './PanelGlyphs';
import { PanelLegend } from './PanelLegend';
import { PanelTooltip, createTooltipStore } from './PanelTooltip';
import {
    LEGEND_ORDER,
    countStates,
    layoutPanel,
    minWidthPx,
    moveStop,
    neighbour,
    portDescription,
    portStateKey,
    tooltipLines,
} from './panelGeometry.helpers';
import type { NavKey, PanelGeometry } from './panelGeometry.helpers';

export interface PortSelection {
    /** `${group.key}:${port.number}`, stable. */
    key: string;
    group: PanelGroup;
    port: PanelPort;
    /** Focus returns here when the sheet closes. */
    trigger: HTMLButtonElement;
}

export interface HardwarePanelProps {
    /** A payload with a model: `template !== null`. */
    panel: PanelPayload;
    /** Accessible name, e.g. "Port OLT-PUSAT-01". */
    label: string;
    /** The port whose sheet is open. */
    selectedKey?: string | null;
    onSelectPort?: (selection: PortSelection) => void;
}

const NAV_KEYS: readonly string[] = [
    'ArrowLeft',
    'ArrowRight',
    'ArrowUp',
    'ArrowDown',
    'Home',
    'End',
];

/**
 * Numbers and captions follow the drawing (4.4 units tall) but stay between 12px and 16px, so a
 * panel squeezed to the 24px target size is still readable. `--panel-w` is the width in units.
 */
const LABEL_FONT = 'clamp(12px, calc(100cqw / var(--panel-w) * 4.4), 16px)';

const BUTTON_CLASS =
    'absolute cursor-pointer touch-manipulation rounded-sm text-muted-foreground hover:ring-1 hover:ring-ring focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden aria-expanded:ring-2 aria-expanded:ring-ring';

const pct = (value: number, total: number) => `${((value / total) * 100).toFixed(3)}%`;

interface PortButtonsProps {
    geometry: PanelGeometry;
    states: readonly string[];
    labels: readonly string[];
    selectedKey: string | null;
}

/** One overlay `<button>` per port, covering its cell. Memoised: hover and focus never reach it. */
const PortButtons = memo(function PortButtons({
    geometry,
    states,
    labels,
    selectedKey,
}: PortButtonsProps) {
    const { width, height } = geometry;

    return (
        <>
            {geometry.ports.map((port) => (
                <button
                    key={port.key}
                    type="button"
                    data-port-button={port.key}
                    data-i={port.i}
                    data-state={states[port.i]}
                    aria-label={labels[port.i]}
                    aria-haspopup="dialog"
                    aria-expanded={port.key === selectedKey}
                    tabIndex={-1}
                    className={BUTTON_CLASS}
                    style={{
                        left: pct(port.cell.x, width),
                        top: pct(port.cell.y, height),
                        width: pct(port.cell.w, width),
                        height: pct(port.cell.h, height),
                    }}
                >
                    <span
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-x-0 flex items-center justify-center font-medium tabular-nums"
                        style={{
                            ...(port.labelAt === 'top' ? { top: 0 } : { bottom: 0 }),
                            height: pct(port.cell.h - port.glyph.h, port.cell.h),
                            fontSize: LABEL_FONT,
                        }}
                    >
                        {port.printed}
                    </span>
                </button>
            ))}
        </>
    );
});

interface PanelViewProps extends Omit<HardwarePanelProps, 'panel'> {
    panel: PanelPayload;
    template: PanelTemplate;
}

function PanelView({ panel, template, label, selectedKey = null, onSelectPort }: PanelViewProps) {
    const uid = useId().replace(/:/g, '');
    const hintId = `${uid}-hint`;
    const rootRef = useRef<HTMLDivElement>(null);
    const groupRef = useRef<HTMLDivElement>(null);
    const [store] = useState(createTooltipStore);
    // The lazy initialiser runs once, at the first render that has the data.
    useState(() => performance.mark('panel:props'));

    // With ODN rows left out the unit would be drawn nominally tall around a gap, so it takes its content size.
    const heightU = panel.odn_hidden === true ? null : template.height_u;
    const geometry = useMemo(
        () => layoutPanel(template.layout, panel.groups, heightU),
        [template.layout, panel.groups, heightU],
    );
    const ports = useMemo(
        () => geometry.ports.map((port) => panel.groups[port.group].ports[port.port]),
        [geometry, panel.groups],
    );
    const states = useMemo(
        () => ports.map((port) => portStateKey(port, panel.online)),
        [ports, panel.online],
    );
    const labels = useMemo(
        () => ports.map((port, i) => portDescription(port, PORT_STATE[states[i]].label)),
        [ports, states],
    );
    const legend = useMemo(() => {
        const counts = countStates(states);
        return LEGEND_ORDER.filter((key) => counts[key]).map((key) => ({
            key,
            count: counts[key],
        }));
    }, [states]);
    // A payload that changed under an open tooltip may have fewer ports than its index says.
    const lines = useCallback(
        (i: number) =>
            ports[i]
                ? tooltipLines(ports[i], geometry.ports[i].ordinal, PORT_STATE[states[i]].label)
                : [],
        [ports, geometry, states],
    );
    const selectedIndex = useMemo(
        () =>
            selectedKey === null
                ? -1
                : geometry.ports.findIndex((port) => port.key === selectedKey),
        [geometry, selectedKey],
    );

    // panel:props -> first frame with the ports on screen; slice 07 reads panel:paint and data-panel-ready.
    useLayoutEffect(() => {
        const frame = requestAnimationFrame(() => {
            performance.mark('panel:painted');
            performance.measure('panel:paint', 'panel:props', 'panel:painted');
            rootRef.current?.setAttribute('data-panel-ready', 'true');
        });
        return () => cancelAnimationFrame(frame);
    }, []);

    useLayoutEffect(() => store.hide(), [store, geometry]);

    // The tab stop follows the open sheet; without one it stays where it was, else it starts at the first port.
    useLayoutEffect(() => {
        const group = groupRef.current;
        if (!group) return;
        if (selectedIndex >= 0) moveStop(group, group.children[selectedIndex]);
        else if (!group.querySelector('[tabindex="0"]')) moveStop(group, group.children[0]);
    }, [geometry, selectedIndex]);

    const buttonOf = (target: EventTarget) =>
        target instanceof Element ? target.closest<HTMLButtonElement>('[data-i]') : null;

    const onFocus = (event: FocusEvent<HTMLDivElement>) => {
        const button = buttonOf(event.target);
        if (!button) return;
        moveStop(event.currentTarget, button);
        store.show(Number(button.dataset.i), button);
    };
    const onBlur = (event: FocusEvent<HTMLDivElement>) => {
        const next = event.relatedTarget;
        // Focus moving to another port shows that port's tooltip right away, so keep this one.
        if (next instanceof Node && event.currentTarget.contains(next)) return;
        store.hide();
    };
    const onPointerOver = (event: PointerEvent<HTMLDivElement>) => {
        if (event.pointerType === 'touch') return;
        const button = buttonOf(event.target);
        if (button) store.show(Number(button.dataset.i), button);
    };
    const onPointerOut = (event: PointerEvent<HTMLDivElement>) => {
        const next = event.relatedTarget;
        if (next instanceof Element && next.closest('[data-i]')) return;
        store.hide();
    };
    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key === 'Escape') {
            store.hide();
            return;
        }
        // Alt+Arrow and the like belong to the browser.
        if (!NAV_KEYS.includes(event.key) || event.altKey || event.ctrlKey || event.metaKey) return;
        const from = buttonOf(event.target);
        if (!from) return;
        event.preventDefault();
        const to = neighbour(geometry, Number(from.dataset.i), event.key as NavKey);
        const next = event.currentTarget.children[to];
        if (next instanceof HTMLElement) next.focus();
    };
    const onClick = (event: MouseEvent<HTMLDivElement>) => {
        const button = buttonOf(event.target);
        if (!button) return;
        store.hide();
        if (!onSelectPort) return;
        const hit = geometry.ports[Number(button.dataset.i)];
        const group = panel.groups[hit.group];
        onSelectPort({ key: hit.key, group, port: group.ports[hit.port], trigger: button });
    };

    const { width, height } = geometry;
    const boxStyle = {
        aspectRatio: `${width} / ${height}`,
        '--panel-w': width,
        '--panel-min': `${minWidthPx(geometry, 24)}px`,
        '--panel-min-coarse': `${minWidthPx(geometry, 44)}px`,
        // Past about 3.4px per unit a small unit would look like a poster.
        '--panel-max': `${Math.ceil(width * 3.4)}px`,
    } as CSSProperties;

    return (
        <div ref={rootRef} className="relative flex flex-col gap-3">
            <PanelLegend
                uid={uid}
                items={legend}
                online={panel.online}
                odnHidden={panel.odn_hidden === true}
                truncated={geometry.truncated}
            />
            {geometry.ports.length > 0 ? (
                <>
                    <div className="overflow-x-auto p-0.5">
                        <div
                            className="@container relative w-full max-w-(--panel-max) min-w-(--panel-min) pointer-coarse:min-w-(--panel-min-coarse)"
                            style={boxStyle}
                        >
                            <svg
                                viewBox={`0 0 ${width} ${height}`}
                                aria-hidden="true"
                                focusable="false"
                                className="absolute inset-0 size-full"
                            >
                                <PanelDefs uid={uid} />
                                <Chassis geometry={geometry} />
                                <FixtureGlyphs uid={uid} geometry={geometry} />
                                <PortGlyphs uid={uid} geometry={geometry} states={states} />
                            </svg>
                            <div
                                aria-hidden="true"
                                className="pointer-events-none absolute inset-0"
                            >
                                {geometry.titles.map((title) => (
                                    <span
                                        key={`${title.x}:${title.y}`}
                                        className="absolute truncate leading-tight text-muted-foreground"
                                        style={{
                                            left: pct(title.x, width),
                                            top: pct(title.y + title.h / 2, height),
                                            width: pct(title.w, width),
                                            transform: 'translateY(-50%)',
                                            fontSize: LABEL_FONT,
                                        }}
                                    >
                                        {title.text}
                                    </span>
                                ))}
                            </div>
                            <div
                                ref={groupRef}
                                role="group"
                                aria-label={label}
                                aria-describedby={hintId}
                                className="absolute inset-0"
                                onFocus={onFocus}
                                onBlur={onBlur}
                                onPointerOver={onPointerOver}
                                onPointerOut={onPointerOut}
                                onKeyDown={onKeyDown}
                                onClick={onClick}
                            >
                                <PortButtons
                                    geometry={geometry}
                                    states={states}
                                    labels={labels}
                                    selectedKey={selectedKey}
                                />
                            </div>
                        </div>
                    </div>
                    <p
                        id={hintId}
                        className="sr-only text-xs text-muted-foreground pointer-fine:not-sr-only"
                    >
                        Tombol panah memindah port · Enter membuka detail port
                    </p>
                </>
            ) : null}
            <PanelTooltip store={store} rootRef={rootRef} lines={lines} />
        </div>
    );
}

/** The front panel of a device drawn like the unit: one `<use>` and one button per port, one tooltip. */
export function HardwarePanel({ panel, ...rest }: HardwarePanelProps) {
    const { template } = panel;
    return template === null ? null : <PanelView panel={panel} template={template} {...rest} />;
}
