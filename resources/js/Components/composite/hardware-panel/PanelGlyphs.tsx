import { memo } from 'react';
import type { CSSProperties } from 'react';
import type { FixtureKind, PortKind, PortStateKey } from '@/types/hardware-panel';
import type { PanelGeometry } from './panelGeometry.helpers';

/**
 * What a port looks like in each state, as classes on its `<use>`. The symbols read the state from
 * the custom properties these set (`--fill`, `--hatch`, `--cross`, `--dash`, `--led`, `--bar`), so one
 * `<use>` carries glyph and state. Every state has a cue besides its colour: outline or solid, hatch,
 * cross, dash, and for the three solid ones a dot (online), a bar (offline) or nothing (occupied).
 */
export const STATE_CLASS: Record<PortStateKey, string> = {
    free: 'text-muted-foreground',
    occupied: 'text-(--status-info) [--fill:currentColor]',
    online: 'text-(--status-success) [--fill:currentColor] [--led:1]',
    offline: 'text-(--status-neutral) [--fill:currentColor] [--bar:1]',
    isolir: 'text-(--status-warning) [--fill:currentColor] [--hatch:1]',
    admin_down: 'text-(--status-danger) [--fill:currentColor] [--cross:1]',
    unregistered: 'text-muted-foreground [--dash:3_2]',
};

/** Symbol ids carry a per-panel prefix (`useId`), so two panels on one page never share a definition. */
export const glyphHref = (uid: string, kind: PortKind | FixtureKind) => `#${uid}-g-${kind}`;

// `--sw` lets a mini panel thicken the outline so it stays a hairline after scaling down.
const BODY: CSSProperties = {
    fill: 'var(--fill, none)',
    stroke: 'currentColor',
    strokeWidth: 'var(--sw, 1.5)',
    strokeLinejoin: 'round',
    strokeDasharray: 'var(--dash, none)',
};
const LINE: CSSProperties = {
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 'var(--sw, 1.5)',
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
};
const SOLID: CSSProperties = { fill: 'currentColor' };
const OPENING: CSSProperties = { fill: 'hsl(var(--background))' };
const HATCH: CSSProperties = { opacity: 'var(--hatch, 0)' };
const CROSS: CSSProperties = {
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 2,
    strokeLinecap: 'round',
    opacity: 'var(--cross, 0)',
};
const LED: CSSProperties = { fill: 'currentColor', opacity: 'var(--led, 0)' };
const BAR: CSSProperties = { fill: 'currentColor', opacity: 'var(--bar, 0)' };

const RJ45 = 'M1.5 1.5H26.5V17.5H19V22.5H9V17.5H1.5Z';
const RJ11 = 'M1.5 1.5H18.5V11.5H13.5V14.5H6.5V11.5H1.5Z';

/**
 * One `<symbol>` per port kind and fixture, drawn like the real jack, plus the hatch pattern.
 * Every symbol's viewBox is twice its glyph box (see GLYPH_SIZE), so strokes are 0.75 units wide.
 */
export const PanelDefs = memo(function PanelDefs({ uid }: { uid: string }) {
    const hatch = `url(#${uid}-hatch)`;

    return (
        <defs>
            <pattern
                id={`${uid}-hatch`}
                width="5"
                height="5"
                patternUnits="userSpaceOnUse"
                patternTransform="rotate(45)"
            >
                <rect width="2" height="5" style={OPENING} />
            </pattern>

            <symbol id={`${uid}-g-rj45`} viewBox="0 0 28 24">
                <path d={RJ45} style={BODY} />
                <path d={RJ45} fill={hatch} style={HATCH} />
                <rect x="6" y="5" width="16" height="9" rx="1" style={OPENING} />
                <circle cx="14" cy="9.5" r="2.6" style={LED} />
                <rect x="9.5" y="8.5" width="9" height="2" rx="1" style={BAR} />
                <path d="M7 6L21 13M21 6L7 13" style={CROSS} />
            </symbol>
            <symbol id={`${uid}-g-sfp`} viewBox="0 0 28 18">
                <rect x="1" y="1" width="26" height="16" rx="1.5" style={BODY} />
                <rect x="1" y="1" width="26" height="16" rx="1.5" fill={hatch} style={HATCH} />
                <rect x="5" y="5" width="18" height="8" rx="1" style={OPENING} />
                <circle cx="14" cy="9" r="2.4" style={LED} />
                <rect x="9.5" y="8" width="9" height="2" rx="1" style={BAR} />
                <path d="M6 6L22 12M22 6L6 12" style={CROSS} />
            </symbol>
            <symbol id={`${uid}-g-sc`} viewBox="0 0 18 18">
                <rect x="1" y="1" width="16" height="16" rx="2" style={BODY} />
                <rect x="1" y="1" width="16" height="16" rx="2" fill={hatch} style={HATCH} />
                <circle cx="9" cy="9" r="5" style={OPENING} />
                <circle cx="9" cy="9" r="2.2" style={SOLID} />
                <circle
                    cx="9"
                    cy="9"
                    r="3.8"
                    style={{ ...CROSS, strokeWidth: 1.2, opacity: 'var(--led, 0)' }}
                />
                <rect x="5.4" y="8.2" width="7.2" height="1.6" rx="0.8" style={BAR} />
                <path d="M5.5 5.5L12.5 12.5M12.5 5.5L5.5 12.5" style={CROSS} />
            </symbol>
            <symbol id={`${uid}-g-rj11`} viewBox="0 0 20 16">
                <path d={RJ11} style={BODY} />
                <path d={RJ11} fill={hatch} style={HATCH} />
                <rect x="5" y="4" width="10" height="5" rx="0.8" style={OPENING} />
                <circle cx="10" cy="6.5" r="1.7" style={LED} />
                <rect x="6.8" y="5.8" width="6.4" height="1.4" rx="0.7" style={BAR} />
                <path d="M6 4.8L14 8.2M14 4.8L6 8.2" style={CROSS} />
            </symbol>

            <symbol id={`${uid}-g-power`} viewBox="0 0 18 18">
                <circle cx="9" cy="9" r="7.5" style={LINE} />
                <circle cx="9" cy="9" r="2.6" style={SOLID} />
            </symbol>
            <symbol id={`${uid}-g-console`} viewBox="0 0 28 24">
                <path d={RJ45} style={{ ...LINE, strokeDasharray: '2 1.5' }} />
                <path d="M7 9.5H21" style={LINE} />
            </symbol>
            <symbol id={`${uid}-g-usb`} viewBox="0 0 24 10">
                <rect x="1" y="1" width="22" height="8" rx="1.5" style={LINE} />
                <rect x="5" y="3.5" width="14" height="3" style={SOLID} />
            </symbol>
            <symbol id={`${uid}-g-reset`} viewBox="0 0 8 8">
                <circle cx="4" cy="4" r="2.9" style={LINE} />
            </symbol>
            <symbol id={`${uid}-g-wlan`} viewBox="0 0 24 24">
                <path
                    d="M4 9A11 11 0 0 1 20 9M7 12.5A7 7 0 0 1 17 12.5M10 16A3 3 0 0 1 14 16"
                    style={LINE}
                />
                <circle cx="12" cy="19" r="1.5" style={SOLID} />
            </symbol>
        </defs>
    );
});

interface GlyphsProps {
    uid: string;
    geometry: PanelGeometry;
}

/** The chassis: one outline and a thin rule between two rows. */
export const Chassis = memo(function Chassis({
    geometry,
    hairline = 0.5,
}: {
    geometry: PanelGeometry;
    hairline?: number;
}) {
    const { width, height, bands, rack } = geometry;

    return (
        <g>
            <rect
                x={hairline / 2}
                y={hairline / 2}
                width={width - hairline}
                height={height - hairline}
                rx={rack ? 2 : 4}
                strokeWidth={hairline}
                className="fill-muted stroke-input"
            />
            {bands.slice(1).map((band) => (
                <rect
                    key={band.y}
                    x={0}
                    y={band.y - 0.75}
                    width={width}
                    height={hairline}
                    className="fill-input"
                />
            ))}
        </g>
    );
});

export const FixtureGlyphs = memo(function FixtureGlyphs({ uid, geometry }: GlyphsProps) {
    return (
        <g className="text-muted-foreground">
            {geometry.fixtures.map(({ kind, glyph }) => (
                <use
                    key={`${kind}:${glyph.x}:${glyph.y}`}
                    href={glyphHref(uid, kind)}
                    x={glyph.x}
                    y={glyph.y}
                    width={glyph.w}
                    height={glyph.h}
                    data-fixture={kind}
                />
            ))}
        </g>
    );
});

/** One `<use>` per port, memoised on the geometry and the states, so selecting or hovering a port never redraws them. */
export const PortGlyphs = memo(function PortGlyphs({
    uid,
    geometry,
    states,
}: GlyphsProps & { states: readonly PortStateKey[] }) {
    return (
        <g>
            {geometry.ports.map((port) => (
                <use
                    key={port.key}
                    href={glyphHref(uid, port.kind)}
                    x={port.glyph.x}
                    y={port.glyph.y}
                    width={port.glyph.w}
                    height={port.glyph.h}
                    className={STATE_CLASS[states[port.i]]}
                    data-port={port.key}
                    data-state={states[port.i]}
                />
            ))}
        </g>
    );
});

interface MiniPanelProps extends GlyphsProps {
    x: number;
    y: number;
    scale: number;
    /** The one port drawn in a state colour and ringed; every other port is drawn free. */
    lit?: { key: string; state: PortStateKey } | null;
}

/** The faceplate alone, scaled into a rack bar or a trace card; it reuses the symbols of the composite's own `<defs>`. */
export function MiniPanel({ uid, geometry, x, y, scale, lit = null }: MiniPanelProps) {
    // Thicken the symbol outline and the chassis edge so they stay about a pixel wide after scaling down.
    const style = { '--sw': Math.min(4, Math.max(1.5, 2.2 / scale)) } as CSSProperties;
    const litPort = lit ? geometry.ports.find((port) => port.key === lit.key) : undefined;

    return (
        <g transform={`translate(${x} ${y}) scale(${scale})`} style={style}>
            <Chassis geometry={geometry} hairline={Math.max(0.5, 1 / scale)} />
            <FixtureGlyphs uid={uid} geometry={geometry} />
            <g className={STATE_CLASS.free}>
                {geometry.ports.map((port) => (
                    <use
                        key={port.key}
                        href={glyphHref(uid, port.kind)}
                        x={port.glyph.x}
                        y={port.glyph.y}
                        width={port.glyph.w}
                        height={port.glyph.h}
                    />
                ))}
            </g>
            {lit && litPort ? (
                <>
                    <use
                        href={glyphHref(uid, litPort.kind)}
                        x={litPort.glyph.x}
                        y={litPort.glyph.y}
                        width={litPort.glyph.w}
                        height={litPort.glyph.h}
                        className={STATE_CLASS[lit.state]}
                        data-lit={lit.key}
                    />
                    <rect
                        x={litPort.glyph.x - 2}
                        y={litPort.glyph.y - 2}
                        width={litPort.glyph.w + 4}
                        height={litPort.glyph.h + 4}
                        rx={2}
                        strokeWidth={2 / scale}
                        className="fill-none stroke-ring"
                    />
                </>
            ) : null}
        </g>
    );
}
