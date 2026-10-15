import { useCompany } from '@/hooks/useCompany';
import { formatRupiah, formatRupiahCompact } from '@/lib/format';

/**
 * A money figure of a summary card: short (`Rp 1,2 jt`) to read at a glance, with the exact rupiah in the
 * tooltip and in the text a screen reader announces.
 */
export function MoneyFigure({ value }: { value: number | string }) {
    const company = useCompany();
    const exact = formatRupiah(value, company);

    return (
        <span title={exact}>
            <span aria-hidden="true">{formatRupiahCompact(value, company)}</span>
            <span className="sr-only">{exact}</span>
        </span>
    );
}
