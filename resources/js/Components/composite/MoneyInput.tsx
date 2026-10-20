import { useState } from 'react';
import { Input } from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { formatRupiah, typedRupiah } from '@/lib/format';

interface MoneyInputProps {
    value: number | null;
    onChange: (value: number | null) => void;
    id?: string;
    name?: string;
    label?: string;
    error?: string;
    hint?: string;
    required?: boolean;
    disabled?: boolean;
    placeholder?: string;
}

/**
 * An amount written the way the company writes money: `Rp 147.701,62`. Dots group the thousands as you
 * type and a comma starts the cents, two at most, so a new amount is whole rupiah and a stored balance with
 * cents can still be entered. What is typed stays as typed until the field loses focus (a comma typed
 * before the cents is not taken away), and the cents of a stored amount are never read as whole digits.
 */
export function MoneyInput({ value, onChange, ...props }: MoneyInputProps) {
    const company = useCompany();
    const written = (amount: number | null) =>
        amount === null ? '' : formatRupiah(amount, company);
    const [text, setText] = useState(written(value));
    // The amount `text` stands for. When the parent sends another one (a reset, a package pick), show it.
    const [shownFor, setShownFor] = useState(value);

    if (!Object.is(value, shownFor)) {
        setShownFor(value);
        setText(written(value));
    }

    return (
        <Input
            {...props}
            inputMode="decimal"
            autoComplete="off"
            value={text}
            onChange={(event) => {
                const typed = typedRupiah(event.target.value, company);

                setText(typed.text);
                setShownFor(typed.value);
                onChange(typed.value);
            }}
            onBlur={() => setText(written(value))}
        />
    );
}
