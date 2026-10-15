import type { IpUsage } from '@/types/ipam-odn';

/** What each usage means, shown under the usage field of the prefix form. The label and tone are in `IP_PREFIX_USAGE`. */
export const usageHints: Record<IpUsage, string> = {
    container: 'Mengelompokkan prefiks lain. Tidak memuat alamat sendiri.',
    customer_static: 'Pool alamat statis pelanggan, diberikan dari halaman langganan.',
    customer_dynamic: 'Pool yang dibagikan router. Hanya ukurannya yang dicatat di sini.',
    infrastructure: 'Alamat perangkat dan manajemen, dicadangkan secara manual.',
};

/** Exact decimal string with thousands separators; an IPv6 size must not pass through a JS number. */
export function formatCount(value: string | number | null | undefined): string {
    if (value === null || value === undefined) return '-';

    const text = String(value);

    return /^\d+$/.test(text) ? BigInt(text).toLocaleString('id-ID') : text;
}

export function formatPercent(value: number): string {
    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(value);
}
