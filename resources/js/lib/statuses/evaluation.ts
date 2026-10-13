import type { StatusMap } from './types';

/** What an evaluation is about; stored as the model's class alias. */
export const EVALUATION_REFERENCE_TYPE = {
    WorkOrder: { label: 'SPK', tone: 'neutral' },
    Ticket: { label: 'Tiket', tone: 'neutral' },
} satisfies StatusMap;
