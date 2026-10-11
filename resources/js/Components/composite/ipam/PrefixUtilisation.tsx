import { UtilisationBar } from '@/Components/composite/UtilisationBar';
import type { PrefixRow } from '@/types/ipam-odn';

interface PrefixUtilisationProps {
    prefix: Pick<PrefixRow, 'cidr' | 'usage' | 'percent'>;
}

/** A container is measured by the share given to child prefixes, an address pool by the addresses in use. */
export function PrefixUtilisation({ prefix }: PrefixUtilisationProps) {
    const measure =
        prefix.usage === 'container' ? 'allocated to child prefixes' : 'addresses in use';

    return <UtilisationBar percent={prefix.percent} label={`${prefix.cidr} ${measure}`} />;
}
