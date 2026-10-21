import { formatNumber } from '@/lib/format';
import type { ApprovalSummaryMaterial } from '@/types';

/** The id a row has across domains: the server's `composite_id`, or the domain and the record id. */
export function getItemCompositeId(item: {
    id: string;
    domain?: string;
    composite_id?: string;
}): string {
    return item.composite_id || `${item.domain || 'unknown'}:${item.id}`;
}

/** A quantity with its own unit: "18 m", "2 pcs". */
export function materialQuantity(material: ApprovalSummaryMaterial): string {
    return [formatNumber(material.quantity), material.unit].filter(Boolean).join(' ');
}

/**
 * The first materials, each in its own unit: "Kabel Drop 18 m, Konektor 2 pcs (+1)". The quantities are never added
 * together, so there is no total to read wrongly.
 */
export function materialsLine(materials: ApprovalSummaryMaterial[], shown = 2): string {
    const line = materials
        .slice(0, shown)
        .map((material) => `${material.product} ${materialQuantity(material)}`)
        .join(', ');
    const rest = materials.length - shown;

    return rest > 0 ? `${line} (+${rest})` : line;
}
