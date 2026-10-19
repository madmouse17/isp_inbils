import type { PanelLayout, PortKind } from '@/types/hardware-panel';
import type { NetworkAssetType } from '@/types/network-asset';

/*
 * The layout "Susun manual" sends: lines of (port kind, count, name prefix) become one chassis row each.
 * Pure, with `import type` only, so `node` runs it as it is (manualLayout.helpers.test.mjs). The server
 * (PanelLayout::rules and PanelLayout::problems) validates the result again; this only keeps the form
 * from sending what it will refuse.
 */

/** Same cap as PanelLayout::MAX_PORTS. */
export const MANUAL_PORT_LIMIT = 256;
/** A chassis has at most 8 rows and a block at most 64 ports (PanelLayout::rules). */
export const MANUAL_MAX_LINES = 8;
export const MANUAL_MAX_COUNT = 64;
/** From this many ports a line is drawn in two rows, as real 1U units do. */
const STACK_FROM = 13;

export interface ManualLine {
    kind: PortKind;
    count: number;
    prefix: string;
}

/** The kinds a unit of this type can have: an ONT has fibre, LAN and telephone; the rest have LAN and SFP. */
export function manualKinds(assetType: NetworkAssetType): PortKind[] {
    return assetType === 'onu_ont' ? ['rj45', 'sc', 'rj11'] : ['rj45', 'sfp'];
}

/**
 * The group key and the name prefix real units print (ether1, sfp1, PON1, LAN1, TEL1). The group keys are
 * the ones the catalogue uses, so a later model change keeps the links of the ports both have.
 */
export function manualNames(
    kind: PortKind,
    assetType: NetworkAssetType,
): { group: string; prefix: string } {
    if (kind === 'rj45' && assetType === 'onu_ont') return { group: 'lan', prefix: 'LAN' };

    return {
        rj45: { group: 'ether', prefix: 'ether' },
        sfp: { group: 'sfp', prefix: 'sfp' },
        sc: { group: 'pon', prefix: 'PON' },
        rj11: { group: 'tel', prefix: 'TEL' },
    }[kind];
}

export function manualTotal(lines: readonly ManualLine[]): number {
    return lines.reduce((sum, line) => sum + (line.count > 0 ? line.count : 0), 0);
}

/** Why these lines cannot be sent, in Indonesian; null when they can. */
export function manualProblem(lines: readonly ManualLine[]): string | null {
    if (lines.length === 0) return 'Tambah sedikitnya satu baris port.';
    if (lines.length > MANUAL_MAX_LINES) return `Paling banyak ${MANUAL_MAX_LINES} baris.`;
    if (
        !lines.every(
            (line) =>
                Number.isInteger(line.count) && line.count >= 1 && line.count <= MANUAL_MAX_COUNT,
        )
    )
        return `Jumlah port tiap baris harus 1 sampai ${MANUAL_MAX_COUNT}.`;

    const total = manualTotal(lines);

    return total > MANUAL_PORT_LIMIT
        ? `Satu perangkat paling banyak ${MANUAL_PORT_LIMIT} port; susunan ini ${total}.`
        : null;
}

/**
 * One chassis row per line. Lines of one kind share a group and continue its numbering (`from`), so
 * ether1-24 followed by another RJ45 line gives ether25 onwards. Each block is captioned with its
 * range, because two groups both start their printed numbers at 1.
 */
export function manualLayout(
    lines: readonly ManualLine[],
    assetType: NetworkAssetType,
): PanelLayout {
    const taken = new Map<string, number>();

    return {
        rows: lines.map((line) => {
            const names = manualNames(line.kind, assetType);
            const from = (taken.get(names.group) ?? 0) + 1;
            const to = from + line.count - 1;
            const prefix = line.prefix.replace(/[{}]/g, '').trim() || names.prefix;
            taken.set(names.group, to);

            return [
                {
                    group: names.group,
                    kind: line.kind,
                    count: line.count,
                    ...(from > 1 ? { from } : {}),
                    ...(line.count >= STACK_FROM
                        ? { stack: 2 as const, order: 'column' as const }
                        : {}),
                    name: `${prefix}{port}`,
                    title: from === to ? `${prefix}${from}` : `${prefix}${from}-${to}`,
                },
            ];
        }),
    };
}
