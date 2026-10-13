import { INVENTORY_REQUEST_STATUS } from './inventory';
import { TICKET_STATUS } from './ticket';
import type { StatusMap } from './types';
import { WORK_ORDER_STATUS } from './workOrder';

/**
 * A row in Dispatch, My Work or Approvals is a work order, a ticket or an inventory request. The
 * three sets say the same thing for a value they share (the selfcheck holds that), so one map
 * serves every row.
 */
export const WORK_ITEM_STATUS = {
    ...INVENTORY_REQUEST_STATUS,
    ...TICKET_STATUS,
    ...WORK_ORDER_STATUS,
} satisfies StatusMap;

/** Which kind of row it is. */
export const WORK_ITEM_DOMAIN = {
    spk: { label: 'SPK', tone: 'neutral' },
    ticket: { label: 'Tiket', tone: 'neutral' },
    inventory_request: { label: 'Permintaan barang', tone: 'neutral' },
} satisfies StatusMap;
