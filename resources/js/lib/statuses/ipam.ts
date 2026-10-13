import type { StatusMap } from './types';

/** What a prefix is for. A container only groups other prefixes and holds no addresses. */
export const IP_PREFIX_USAGE = {
    container: { label: 'Induk', tone: 'neutral' },
    customer_static: { label: 'IP statis pelanggan', tone: 'neutral' },
    customer_dynamic: { label: 'IP dinamis pelanggan', tone: 'neutral' },
    infrastructure: { label: 'Infrastruktur', tone: 'neutral' },
} satisfies StatusMap;

export const IP_ADDRESS_STATUS = {
    assigned: { label: 'Dipakai', tone: 'info' },
    reserved: { label: 'Dicadangkan', tone: 'warning' },
} satisfies StatusMap;

/** `family` of a prefix: pass the number itself. */
export const IP_VERSION = {
    4: { label: 'IPv4', tone: 'neutral' },
    6: { label: 'IPv6', tone: 'neutral' },
} satisfies StatusMap;

/** What holds an address. */
export const IP_HOLDER_TYPE = {
    subscription: { label: 'Langganan', tone: 'neutral' },
    asset: { label: 'Aset', tone: 'neutral' },
} satisfies StatusMap;
