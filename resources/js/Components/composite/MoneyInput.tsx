import { Input } from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { formatRupiah } from '@/lib/format';

interface MoneyInputProps {
    value: number | null;
    onChange: (value: number | null) => void;
    id?: string;
    name?: string;
    disabled?: boolean;
    placeholder?: string;
}

export function MoneyInput({ value, onChange, ...props }: MoneyInputProps) {
    const company = useCompany();

    return (
        <Input
            {...props}
            inputMode="numeric"
            value={value === null ? '' : formatRupiah(value, company)}
            onChange={(event) => {
                const numeric = event.target.value.replace(/[^0-9]/g, '');
                onChange(numeric === '' ? null : Number(numeric));
            }}
        />
    );
}
