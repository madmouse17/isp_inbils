import { PORT_STATE } from '@/lib/statuses/network';
import type { PanelOnline, PortStateKey } from '@/types/hardware-panel';
import { STATE_CLASS, glyphHref } from './PanelGlyphs';
import { PANEL_PORT_LIMIT } from './panelGeometry.helpers';

export interface PanelLegendProps {
    /** Prefix of the symbol ids of the panel this legend belongs to. */
    uid: string;
    /** States to list, in the order shown; `count` is printed after the label when given. */
    items: { key: PortStateKey; count?: number }[];
    online: PanelOnline;
    /** ODN-backed groups were left out for lack of odn.view. */
    odnHidden?: boolean;
    /** Ports beyond the display limit that were not drawn. */
    truncated?: number;
}

function onlineNote(online: PanelOnline): string {
    if (online.known) return 'Online = sesi RADIUS terbuka, diperbarui paling lama tiap 15 detik.';
    if (online.reason === 'disabled') return 'Status online tidak tersedia: RADIUS nonaktif.';
    if (online.reason === 'unavailable')
        return 'Status online tidak tersedia: RADIUS tidak dapat dihubungi.';
    return 'Status online tidak tersedia.';
}

/** The colours in words: every state is a swatch with its label, so no state is told by colour alone. */
export function PanelLegend({
    uid,
    items,
    online,
    odnHidden = false,
    truncated = 0,
}: PanelLegendProps) {
    const notes = [
        items.some(({ key }) => key === 'online' || key === 'offline' || !online.known)
            ? onlineNote(online)
            : null,
        odnHidden ? 'Port PON dan splitter tampil untuk pengguna dengan akses ODN.' : null,
        truncated > 0
            ? `Hanya ${PANEL_PORT_LIMIT} port pertama yang digambar; ${truncated} port tidak tampil.`
            : null,
    ].filter((note) => note !== null);

    return (
        <div className="space-y-1.5 max-sm:sticky max-sm:top-0 max-sm:z-10 max-sm:border-b max-sm:bg-card max-sm:py-2">
            {items.length > 0 ? (
                <ul
                    aria-label="Legenda status port"
                    className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm"
                >
                    {items.map(({ key, count }) => (
                        <li key={key} className="inline-flex items-center gap-1.5">
                            <svg
                                width="21"
                                height="14"
                                viewBox="0 0 21 14"
                                aria-hidden="true"
                                focusable="false"
                                className="shrink-0"
                            >
                                <use
                                    href={glyphHref(uid, 'sfp')}
                                    width="21"
                                    height="14"
                                    className={STATE_CLASS[key]}
                                />
                            </svg>
                            <span className="text-foreground">{PORT_STATE[key].label}</span>
                            {count !== undefined ? (
                                <span className="text-muted-foreground tabular-nums">{count}</span>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : null}
            {notes.map((note) => (
                <p key={note} className="text-xs text-muted-foreground">
                    {note}
                </p>
            ))}
        </div>
    );
}
