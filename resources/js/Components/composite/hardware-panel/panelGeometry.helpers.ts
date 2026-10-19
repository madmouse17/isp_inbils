import type {
    FixtureBlock,
    FixtureKind,
    PanelGroup,
    PanelLayout,
    PanelOnline,
    PanelPort,
    PortBlock,
    PortKind,
    PortState,
    PortStateKey,
    RackItem,
    TraceHop,
} from '@/types/hardware-panel';

/*
 * Pure geometry of the hardware panel: no React, no DOM, and only `import type` from the app, so
 * `node` runs this file as it is (panelGeometry.helpers.test.mjs). Units are millimetres of the
 * real face; 1U is 44.45.
 */

/**
 * shortcut: cap 256 ports, virtualise rows when a model above 256 is catalogued.
 * PanelLayout::MAX_PORTS refuses larger layouts, so the cap only guards a payload that bypassed it.
 */
export const PANEL_PORT_LIMIT = 256;

export const UNIT_HEIGHT = 44.45;
export const RACK_BODY_WIDTH = 440;

export interface Box {
    x: number;
    y: number;
    w: number;
    h: number;
}

export interface GeoPort {
    /** Index in `PanelGeometry.ports`, also the `data-i` of its button. Reading order: rows top to bottom, blocks left to right, ports by number. */
    i: number;
    /** `${group}:${number}`, the stable key of the port. */
    key: string;
    /** Index into the `groups` given to `layoutPanel`; -1 in `layoutStatic`. */
    group: number;
    /** Index into `groups[group].ports`; -1 in `layoutStatic`. */
    port: number;
    number: number;
    kind: PortKind;
    glyph: Box;
    /** Hit area: the glyph plus the strip that carries the printed number. */
    cell: Box;
    labelAt: 'top' | 'bottom';
    /** The number printed on the unit: `number - 1 + first`, so a Huawei card starts at 0. */
    printed: number;
    /** Position of a PON port in the ODN register (1-based per OLT, the `odn_ports.number`); null for any other port. */
    ordinal: number | null;
}

export interface GeoFixture {
    kind: FixtureKind;
    glyph: Box;
}

export interface GeoTitle {
    text: string;
    x: number;
    y: number;
    /** Room for the caption: its block and the gap up to the next one; wider text is cut with an ellipsis. */
    w: number;
    h: number;
}

export interface PanelGeometry {
    width: number;
    height: number;
    ports: GeoPort[];
    fixtures: GeoFixture[];
    titles: GeoTitle[];
    /** Chassis rows, top to bottom; a 1-unit rule separates two of them. */
    bands: { y: number; h: number }[];
    /** Smallest side of any hit area, in units. */
    minCell: number;
    /** Rack-mount body (template has a height in U): squarer corners. */
    rack: boolean;
    /** Ports beyond PANEL_PORT_LIMIT that were not drawn. */
    truncated: number;
}

const GLYPH_SIZE = {
    rj45: [14, 12],
    sfp: [14, 9],
    sc: [9, 9],
    rj11: [10, 8],
    power: [9, 9],
    console: [14, 12],
    usb: [12, 5],
    wlan: [10, 10],
    reset: [4, 4],
} as const satisfies Record<PortKind | FixtureKind, readonly [number, number]>;

const PAD_X = 6;
const PAD_Y = 3;
const BLOCK_GAP = 6;
const RULE = 1;
const CELL_PAD_X = 2;
const NUMBER_STRIP = 5;
const STACK_GAP = 1;
const TITLE_STRIP = 6;
/** Width of a caption character in units at the 12px floor of the caption size (capitals run wide), so a short block still fits its title. */
const TITLE_CHAR_W = 3.4;
const ORPHAN_ROW_PORTS = 16;

/** What real units print next to a fixture that the layout gave no title. */
const FIXTURE_CAPTION = {
    power: 'PWR',
    console: 'CON',
    usb: 'USB',
    wlan: 'WLAN',
    reset: 'RST',
} as const satisfies Record<FixtureKind, string>;

interface Slot {
    number: number;
    row: 0 | 1;
    col: number;
    printed: number;
    ordinal: number | null;
    group: number;
    port: number;
}

interface PortItem {
    type: 'ports';
    groupKey: string;
    kind: PortKind;
    stack: 1 | 2;
    slots: Slot[];
    caption: string | null;
    w: number;
    h: number;
}

interface FixtureItem {
    type: 'fixture';
    kind: FixtureKind;
    caption: string;
    w: number;
    h: number;
}

type Item = PortItem | FixtureItem;

const round = (value: number) => Math.round(value * 1000) / 1000;
const captionWidth = (text: string) => Math.ceil(text.length * TITLE_CHAR_W);

/** Where port `i` of a block sits: stack 2 'column' is 1 top, 2 bottom, 3 top; 'row' fills the top row first. */
function slotOf(block: PortBlock, i: number): { row: 0 | 1; col: number } {
    if ((block.stack ?? 1) === 1) return { row: 0, col: i };
    if (block.order === 'row') {
        const half = Math.ceil(block.count / 2);
        return i < half ? { row: 0, col: i } : { row: 1, col: i - half };
    }
    return { row: i % 2 === 0 ? 0 : 1, col: Math.floor(i / 2) };
}

function portItem(block: PortBlock, slots: Slot[]): PortItem {
    const stack = block.stack ?? 1;
    const [gw, gh] = GLYPH_SIZE[block.kind];
    const cols = stack === 2 ? Math.ceil(block.count / 2) : block.count;
    const caption = block.title ?? null;

    return {
        type: 'ports',
        groupKey: block.group,
        kind: block.kind,
        stack,
        slots,
        caption,
        w: Math.max(cols * (gw + CELL_PAD_X), caption ? captionWidth(caption) : 0),
        // Numbers sit above the top row and below the bottom row; a single row has them below.
        h: stack === 2 ? 2 * gh + STACK_GAP + 2 * NUMBER_STRIP : gh + NUMBER_STRIP,
    };
}

function fixtureItem(block: FixtureBlock): FixtureItem {
    const [gw, gh] = GLYPH_SIZE[block.fixture];
    const caption = block.title ?? FIXTURE_CAPTION[block.fixture];

    return {
        type: 'fixture',
        kind: block.fixture,
        caption,
        w: Math.max(gw + CELL_PAD_X, captionWidth(caption)),
        h: gh,
    };
}

/**
 * Turns the layout into rows of items. With `groups`, a slot becomes a port only when the payload
 * holds it: a block whose group was left out (no odn.view) is skipped, and payload ports no block
 * places are appended as extra rows, so no register row is ever hidden. Without `groups` every slot
 * of the layout is a port (mini panels of the rack and the trace).
 */
function plan(
    layout: PanelLayout,
    groups: PanelGroup[] | null,
): { rows: Item[][]; truncated: number } {
    const index = new Map<string, { group: number; port: number }>();
    groups?.forEach((group, g) =>
        group.ports.forEach((port, p) =>
            index.set(`${group.key}:${port.number}`, { group: g, port: p }),
        ),
    );
    const known = new Set(groups?.map((group) => group.key));
    const seen = new Set<string>();
    let drawn = 0;
    let truncated = 0;

    const take = (key: string): { group: number; port: number } | null => {
        seen.add(key);
        const hit = groups ? (index.get(key) ?? null) : { group: -1, port: -1 };
        if (hit === null) return null;
        if (drawn >= PANEL_PORT_LIMIT) {
            truncated += 1;
            return null;
        }
        drawn += 1;
        return hit;
    };

    const rows: Item[][] = [];
    let ponBase = 0;

    for (const row of layout.rows) {
        const items: Item[] = [];
        let ports = 0;

        for (const block of row) {
            if ('fixture' in block) {
                items.push(fixtureItem(block));
                continue;
            }

            // The ODN ordinal counts the model's PON slots in reading order, drawn or not.
            const ordinalBase = block.role === 'pon' ? ponBase : null;
            if (block.role === 'pon') ponBase += block.count;
            if (groups && !known.has(block.group)) continue;

            const first = block.first ?? 1;
            const from = block.from ?? 1;
            const slots: Slot[] = [];
            for (let i = 0; i < block.count; i += 1) {
                const number = from + i;
                const hit = take(`${block.group}:${number}`);
                if (hit === null) continue;
                slots.push({
                    ...slotOf(block, i),
                    number,
                    printed: number - 1 + first,
                    ordinal: ordinalBase === null ? null : ordinalBase + i + 1,
                    ...hit,
                });
            }
            ports += slots.length;
            items.push(portItem(block, slots));
        }

        if (!groups || ports > 0) rows.push(items);
    }

    groups?.forEach((group, g) => {
        const loose = group.ports
            .map((port, p) => ({ number: port.number, p }))
            .filter(({ number }) => !seen.has(`${group.key}:${number}`))
            .sort((a, b) => a.number - b.number);

        for (let at = 0; at < loose.length; at += ORPHAN_ROW_PORTS) {
            const block: PortBlock = {
                group: group.key,
                kind: group.kind,
                count: Math.min(ORPHAN_ROW_PORTS, loose.length - at),
                title: at === 0 ? group.label : undefined,
            };
            const slots: Slot[] = [];
            loose.slice(at, at + ORPHAN_ROW_PORTS).forEach(({ number, p }, col) => {
                if (take(`${group.key}:${number}`) === null) return;
                slots.push({
                    row: 0,
                    col,
                    number,
                    printed: number,
                    ordinal: null,
                    group: g,
                    port: p,
                });
            });
            rows.push([portItem(block, slots)]);
        }
    });

    return { rows, truncated };
}

function assemble(rows: Item[][], heightU: number | null, truncated: number): PanelGeometry {
    const rack = heightU !== null && heightU > 0;
    const bands = rows.map((items) => {
        const titled = items.some((item) => item.caption !== null);
        const contentH = Math.max(0, ...items.map((item) => item.h));
        return {
            items,
            titled,
            w:
                2 * PAD_X +
                items.reduce((sum, item) => sum + item.w, 0) +
                BLOCK_GAP * Math.max(0, items.length - 1),
            h: 2 * PAD_Y + contentH + (titled ? TITLE_STRIP : 0),
        };
    });

    // A rack unit is at least its height in U; the spare height is shared by the rows.
    const natural =
        bands.reduce((sum, band) => sum + band.h, 0) + RULE * Math.max(0, bands.length - 1);
    const grow =
        rack && bands.length > 0
            ? Math.max(0, (heightU ?? 0) * UNIT_HEIGHT - natural) / bands.length
            : 0;

    const ports: GeoPort[] = [];
    const fixtures: GeoFixture[] = [];
    const titles: GeoTitle[] = [];
    const out: PanelGeometry['bands'] = [];
    let y = 0;

    for (const band of bands) {
        const h = band.h + grow;
        const available = h - 2 * PAD_Y - (band.titled ? TITLE_STRIP : 0);
        let x = PAD_X;

        for (const item of band.items) {
            const top = y + PAD_Y + (available - item.h) / 2;

            if (item.type === 'fixture') {
                const [gw, gh] = GLYPH_SIZE[item.kind];
                fixtures.push({
                    kind: item.kind,
                    glyph: { x: x + CELL_PAD_X / 2, y: round(top), w: gw, h: gh },
                });
            } else {
                const [gw, gh] = GLYPH_SIZE[item.kind];
                const cellW = gw + CELL_PAD_X;
                const cellH = gh + NUMBER_STRIP;

                for (const slot of item.slots) {
                    const lower = item.stack === 2 && slot.row === 1;
                    const cellY = lower ? top + NUMBER_STRIP + gh + STACK_GAP : top;
                    const cellX = x + slot.col * cellW;
                    ports.push({
                        i: ports.length,
                        key: `${item.groupKey}:${slot.number}`,
                        group: slot.group,
                        port: slot.port,
                        number: slot.number,
                        kind: item.kind,
                        glyph: {
                            x: round(cellX + CELL_PAD_X / 2),
                            y: round(item.stack === 2 && !lower ? cellY + NUMBER_STRIP : cellY),
                            w: gw,
                            h: gh,
                        },
                        cell: { x: round(cellX), y: round(cellY), w: cellW, h: cellH },
                        labelAt: item.stack === 1 || lower ? 'bottom' : 'top',
                        printed: slot.printed,
                        ordinal: slot.ordinal,
                    });
                }
            }

            if (item.caption !== null) {
                titles.push({
                    text: item.caption,
                    x: round(x),
                    y: round(y + h - PAD_Y - TITLE_STRIP),
                    w: item.w + BLOCK_GAP - 1,
                    h: TITLE_STRIP,
                });
            }
            x += item.w + BLOCK_GAP;
        }

        out.push({ y: round(y), h: round(h) });
        y += h + RULE;
    }

    const minCell = ports.reduce(
        (least, port) => Math.min(least, port.cell.w, port.cell.h),
        Infinity,
    );

    return {
        width: round(
            Math.max(rack ? RACK_BODY_WIDTH : 0, 2 * PAD_X, ...bands.map((band) => band.w)),
        ),
        height: round(bands.length > 0 ? y - RULE : 2 * PAD_Y),
        ports,
        fixtures,
        titles,
        bands: out,
        minCell: Number.isFinite(minCell) ? minCell : 0,
        rack,
        truncated,
    };
}

/** The panel of an asset: the layout drawn with the ports the payload holds. */
export function layoutPanel(
    layout: PanelLayout,
    groups: PanelGroup[],
    heightU: number | null,
): PanelGeometry {
    const { rows, truncated } = plan(layout, groups);
    return assemble(rows, heightU, truncated);
}

/** The faceplate alone, every slot of the layout drawn: the mini panels of the rack and the trace. */
export function layoutStatic(layout: PanelLayout, heightU: number | null): PanelGeometry {
    const { rows, truncated } = plan(layout, null);
    return assemble(rows, heightU, truncated);
}

function stateKeyOf(state: PortState, online: PanelOnline, loaded: boolean): PortStateKey {
    if (state.unregistered) return 'unregistered';
    if (state.admin_down) return 'admin_down';
    if (state.isolir) return 'isolir';
    if (state.online) return 'online';
    if (state.occupied && loaded && online.known) return 'offline';
    return state.occupied ? 'occupied' : 'free';
}

/**
 * Colour priority: unregistered > admin_down > isolir > online > offline > occupied > free.
 * Offline needs a subscriber to be offline (a drop, or subscribers behind) and a known online set.
 */
export function portStateKey(port: PanelPort, online: PanelOnline): PortStateKey {
    return stateKeyOf(port.state, online, port.drop !== undefined || (port.behind?.total ?? 0) > 0);
}

/** The state of a fibre path: it ends at one subscriber, so an occupied path with a known online set is offline when not online. */
export function pathStateKey(state: PortState, online: PanelOnline): PortStateKey {
    return stateKeyOf(state, online, true);
}

/** Legend order; PORT_STATE lists the keys by colour priority instead. */
export const LEGEND_ORDER = [
    'online',
    'offline',
    'isolir',
    'occupied',
    'free',
    'admin_down',
    'unregistered',
] as const satisfies readonly PortStateKey[];

export function countStates(
    states: readonly PortStateKey[],
): Partial<Record<PortStateKey, number>> {
    const counts: Partial<Record<PortStateKey, number>> = {};
    for (const state of states) counts[state] = (counts[state] ?? 0) + 1;
    return counts;
}

/** The accessible name of a port button: "{name}{, label}: {state}{, ke {asset} {port}}{, pelanggan {sub} {customer}}{, {n} pelanggan, {m} online}". */
export function portDescription(port: PanelPort, stateLabel: string): string {
    let text = `${port.label ? `${port.name}, ${port.label}` : port.name}: ${stateLabel}`;
    if (port.link) text += `, ke ${port.link.asset} ${port.link.port}`;
    if (port.drop) text += `, pelanggan ${port.drop.subscription} ${port.drop.customer}`;
    if (port.behind) text += `, ${port.behind.total} pelanggan, ${port.behind.online} online`;
    return text;
}

/** The lines of the hover tooltip; the first is the heading. A PON port also says which port it is in the ODN register. */
export function tooltipLines(
    port: PanelPort,
    ordinal: number | null,
    stateLabel: string,
): string[] {
    const lines = [`${port.name} · ${stateLabel}`];
    if (ordinal !== null) lines.push(`PON ${ordinal} di ODN`);
    if (port.label) lines.push(port.label);
    if (port.link) lines.push(`ke ${port.link.asset} · ${port.link.port}`);
    if (port.drop) lines.push(`${port.drop.subscription} · ${port.drop.customer}`);
    if (port.behind) lines.push(`${port.behind.total} pelanggan · ${port.behind.online} online`);
    return lines;
}

export type NavKey = 'ArrowLeft' | 'ArrowRight' | 'ArrowUp' | 'ArrowDown' | 'Home' | 'End';

/** Cells of one row band sit within this distance of each other's centre (a single row next to a stack of two is half a row off). */
const BAND_REACH = 9;

/**
 * The port a navigation key moves to; `from` when there is none. Left and right stay in the row
 * band, up and down take the nearest row above or below and then the closest column, Home and End
 * go to the first and the last port.
 */
export function neighbour(geometry: PanelGeometry, from: number, key: NavKey): number {
    const { ports } = geometry;
    const origin = ports[from];
    if (origin === undefined) return from;
    if (key === 'Home') return 0;
    if (key === 'End') return ports.length - 1;

    const cx = origin.cell.x + origin.cell.w / 2;
    const cy = origin.cell.y + origin.cell.h / 2;
    const sideways = key === 'ArrowLeft' || key === 'ArrowRight';
    const forward = key === 'ArrowRight' || key === 'ArrowDown';
    let best = from;
    let bestScore = Infinity;

    for (const port of ports) {
        if (port.i === from) continue;
        const dx = port.cell.x + port.cell.w / 2 - cx;
        const dy = port.cell.y + port.cell.h / 2 - cy;
        const along = sideways ? dx : dy;
        if (forward ? along <= 1 : along >= -1) continue;
        if (sideways && Math.abs(dy) > BAND_REACH) continue;

        const score = sideways ? Math.abs(dx) + Math.abs(dy) : Math.abs(dy) + 2 * Math.abs(dx);
        if (score < bestScore) {
            best = port.i;
            bestScore = score;
        }
    }

    return best;
}

/**
 * Narrowest the panel may be so that every hit area is `targetPx` wide and tall (24 on a fine
 * pointer, 44 on touch); a narrower container scrolls sideways instead of shrinking the targets.
 */
export function minWidthPx(geometry: PanelGeometry, targetPx: number): number {
    if (geometry.minCell <= 0) return Math.ceil(geometry.width);
    return Math.ceil((geometry.width * targetPx) / geometry.minCell);
}

/**
 * Roving tabindex of a group of buttons: one tab stop, the last one selected or focused. It takes
 * the elements, so this module still imports without a DOM.
 */
export function moveStop(group: Element, to: Element | undefined): void {
    group.querySelector('[tabindex="0"]')?.setAttribute('tabindex', '-1');
    to?.setAttribute('tabindex', '0');
}

export const RACK_RAIL = 20;
export const RACK_FRAME = 6;

/** A rack drawn at one pixel per unit, so a U slot is 44.45 px: U1 at the bottom (EIA), slots and items top to bottom. */
export function layoutRack(
    heightU: number,
    items: RackItem[],
): {
    width: number;
    height: number;
    slots: { unit: number; y: number }[];
    items: (RackItem & { y: number; h: number })[];
} {
    const slots = Array.from({ length: heightU }, (_, k) => ({
        unit: heightU - k,
        y: round(RACK_FRAME + k * UNIT_HEIGHT),
    }));

    const mounted = items
        .map((item) => {
            const unit = Math.min(Math.max(item.unit, 1), heightU);
            const height = Math.min(Math.max(item.height, 1), heightU - unit + 1);
            const top = unit + height - 1;
            return {
                ...item,
                unit,
                height,
                y: round(RACK_FRAME + (heightU - top) * UNIT_HEIGHT),
                h: round(height * UNIT_HEIGHT),
            };
        })
        .sort((a, b) => a.y - b.y);

    return {
        width: 2 * RACK_RAIL + RACK_BODY_WIDTH,
        height: round(2 * RACK_FRAME + heightU * UNIT_HEIGHT),
        slots,
        items: mounted,
    };
}

export const TRACE = {
    hopW: 184,
    hopH: 124,
    gap: 32,
    pad: 8,
    top: 26,
    areaH: 64,
    maxScale: 1.5,
} as const;

export interface TraceHopLayout {
    /** Left edge of the hop card. */
    x: number;
    card: Box;
    /** Where the mini panel is drawn: it is scaled to fit and centred. */
    area: Box;
    scale: number;
    origin: { x: number; y: number };
    geometry: PanelGeometry | null;
    /** Centre of the lit port in trace coordinates; null without a layout or a lit port. */
    litCentre: { x: number; y: number } | null;
}

/** The chain of a fibre path: one card per hop, left to right, each with its faceplate scaled into the card. */
export function layoutTrace(hops: TraceHop[]): {
    width: number;
    height: number;
    hops: TraceHopLayout[];
} {
    const areaW = TRACE.hopW - 2 * TRACE.pad;

    return {
        width: hops.length > 0 ? hops.length * TRACE.hopW + (hops.length - 1) * TRACE.gap : 0,
        height: TRACE.hopH,
        hops: hops.map((hop, n) => {
            const x = n * (TRACE.hopW + TRACE.gap);
            const area = { x: x + TRACE.pad, y: TRACE.top, w: areaW, h: TRACE.areaH };
            const geometry = hop.layout ? layoutStatic(hop.layout, null) : null;
            const scale =
                geometry !== null && geometry.width > 0 && geometry.height > 0
                    ? Math.min(
                          areaW / geometry.width,
                          TRACE.areaH / geometry.height,
                          TRACE.maxScale,
                      )
                    : 1;
            const origin = {
                x: round(area.x + (areaW - (geometry?.width ?? 0) * scale) / 2),
                y: round(area.y + (TRACE.areaH - (geometry?.height ?? 0) * scale) / 2),
            };
            const lit =
                geometry && hop.lit
                    ? geometry.ports.find(
                          (port) => port.key === `${hop.lit?.group}:${hop.lit?.number}`,
                      )
                    : undefined;

            return {
                x,
                card: { x, y: 0, w: TRACE.hopW, h: TRACE.hopH },
                area,
                scale: round(scale),
                origin,
                geometry,
                litCentre: lit
                    ? {
                          x: round(origin.x + (lit.glyph.x + lit.glyph.w / 2) * scale),
                          y: round(origin.y + (lit.glyph.y + lit.glyph.h / 2) * scale),
                      }
                    : null,
            };
        }),
    };
}
