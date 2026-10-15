import type { StatusMap } from './types';

/** How a bandwidth profile shares the line: `shared` carries a contention ratio, `dedicated` does not. */
export const BANDWIDTH_TYPE = {
    shared: { label: 'Berbagi', tone: 'neutral' },
    dedicated: { label: 'Dedikasi', tone: 'neutral' },
} satisfies StatusMap;
