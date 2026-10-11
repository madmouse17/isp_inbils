import type { IpUsage } from '@/types/ipam-odn';

export const usageLabels: Record<IpUsage, string> = {
    container: 'Container',
    customer_static: 'Customer static',
    customer_dynamic: 'Customer dynamic',
    infrastructure: 'Infrastructure',
};

export const usageOptions = (Object.keys(usageLabels) as IpUsage[]).map((value) => ({
    value,
    label: usageLabels[value],
}));

export const usageHints: Record<IpUsage, string> = {
    container: 'Groups other prefixes. It holds no addresses itself.',
    customer_static: 'Pool for static customer addresses, handed out from the subscription page.',
    customer_dynamic: 'Pool a router hands out. Only its size is tracked here.',
    infrastructure: 'Device and management addresses, reserved by hand.',
};

/** Badge tone per usage, each at least 4.5:1 for its text in light and dark. The label carries the meaning; the tone only groups. */
export const usageVariants: Record<IpUsage, 'outline' | 'info' | 'success' | 'secondary'> = {
    container: 'outline',
    customer_static: 'info',
    customer_dynamic: 'success',
    infrastructure: 'secondary',
};

/** Exact decimal string with thousands separators; an IPv6 size must not pass through a JS number. */
export function formatCount(value: string | number | null | undefined): string {
    if (value === null || value === undefined) return '—';

    const text = String(value);

    return /^\d+$/.test(text) ? BigInt(text).toLocaleString('id-ID') : text;
}

export function formatPercent(value: number): string {
    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(value);
}
