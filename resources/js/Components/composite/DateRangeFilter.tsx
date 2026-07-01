import { Button, Input } from '@/Components/ui';

export interface DateRangeValue {
    from: string;
    to: string;
}

interface DateRangeFilterProps {
    value: DateRangeValue;
    onChange: (value: DateRangeValue) => void;
    onApply?: () => void;
}

export function DateRangeFilter({ value, onChange, onApply }: DateRangeFilterProps) {
    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
            <Input type="date" label="From" value={value.from} onChange={(event) => onChange({ ...value, from: event.target.value })} />
            <Input type="date" label="To" value={value.to} onChange={(event) => onChange({ ...value, to: event.target.value })} />
            <Button type="button" variant="secondary" onClick={onApply}>Apply</Button>
        </div>
    );
}
