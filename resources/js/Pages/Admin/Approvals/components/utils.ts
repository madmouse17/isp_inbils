/** The id a row has across domains: the server's `composite_id`, or the domain and the record id. */
export function getItemCompositeId(item: {
    id: string;
    domain?: string;
    composite_id?: string;
}): string {
    return item.composite_id || `${item.domain || 'unknown'}:${item.id}`;
}
