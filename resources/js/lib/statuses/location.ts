import type { StatusMap } from './types';

export const LOCATION_TYPE = {
    region: { label: 'Wilayah', tone: 'neutral' },
    area: { label: 'Area', tone: 'neutral' },
    pop: { label: 'POP', tone: 'neutral' },
    rack: { label: 'Rak', tone: 'neutral' },
    site: { label: 'Site', tone: 'neutral' },
} satisfies StatusMap;
