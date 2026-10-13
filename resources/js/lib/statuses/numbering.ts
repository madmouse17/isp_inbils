import type { StatusMap } from './types';

/** When a number sequence starts again from 1 (`App\Support\NumberSequences\ResetPeriod`). */
export const RESET_PERIOD = {
    never: { label: 'Tidak pernah', tone: 'neutral' },
    yearly: { label: 'Setiap tahun', tone: 'neutral' },
    monthly: { label: 'Setiap bulan', tone: 'neutral' },
    daily: { label: 'Setiap hari', tone: 'neutral' },
} satisfies StatusMap;
