import type { StatusMap } from './types';

/** What the occupancy table says about an ODP: no splitter yet (capacity 0), or every port taken. Derived, not stored. */
export const ODN_PORT_STATE = {
    no_splitter: { label: 'Tanpa splitter', tone: 'neutral' },
    full: { label: 'Penuh', tone: 'warning' },
} satisfies StatusMap;
