import { Link } from '@inertiajs/react';
import { usePermission } from '@/hooks/usePermission';
import { INVENTORY_REQUEST_SOURCE, labelOf } from '@/lib/statuses';
import type { InventoryRequestRow } from '@/types/inventory';

/** Where the record a request is for opens, and who may open it. The id in the link is the public id the row carries. */
const SOURCE_PAGE: Record<string, { route: string; permission: string }> = {
    work_order: { route: 'admin.spk.show', permission: 'spk.view' },
    ticket: { route: 'admin.tickets.show', permission: 'ticket.view' },
};

type Props = {
    request: Pick<InventoryRequestRow, 'source_type' | 'source_id' | 'source_code'>;
};

/**
 * What a request is for, by the code people use: "SPK SPK-2026-00012". It is a link to that SPK or ticket for a person
 * who may open it, and plain text for anyone else, so nobody lands on a page that refuses them. A record that is gone
 * has no code to show: the kind alone is left.
 */
export function SourceRef({ request }: Props) {
    const { can } = usePermission();

    if (!request.source_type || !request.source_id) return null;

    const kind = labelOf(INVENTORY_REQUEST_SOURCE, request.source_type);
    const text = request.source_code ? `${kind} ${request.source_code}` : kind;
    const page = SOURCE_PAGE[request.source_type];

    if (page === undefined || !request.source_code || !can(page.permission)) {
        return <span className="font-medium whitespace-nowrap text-foreground">{text}</span>;
    }

    return (
        <Link
            href={route(page.route, request.source_id)}
            className="font-medium whitespace-nowrap text-primary-text hover:underline focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden"
        >
            {text}
        </Link>
    );
}
