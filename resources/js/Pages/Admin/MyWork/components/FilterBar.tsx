import type { FormEvent } from 'react';
import { usePage } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { NativeSelect } from '@/Components/composite/NativeSelect';
import { Button, DateRangePicker } from '@/Components/ui';
import { Input } from '@/Components/ui/Input';
import type { ServerTableFilterValue } from '@/hooks/useServerTable';
import { optionsOf, PRIORITY } from '@/lib/statuses';
import type { QueueDomainOption } from '@/types';
import { MY_WORK_STATUS_FILTER } from './utils';

interface FilterBarProps {
    params: Record<string, string>;
    set: (key: string, value: ServerTableFilterValue) => void;
    setMany: (patch: Record<string, ServerTableFilterValue>) => void;
    domains: QueueDomainOption[];
    onSubmitSearch: (event: FormEvent) => void;
    disabled?: boolean;
}

const STATUS_OPTIONS = [{ value: '', label: 'Semua status' }, ...MY_WORK_STATUS_FILTER];

export function FilterBar({
    params,
    set,
    setMany,
    domains,
    onSubmitSearch,
    disabled = false,
}: FilterBarProps) {
    const { errors } = usePage().props as { errors?: Record<string, string | undefined> };
    const hasActiveFilters = Boolean(
        params.search ||
        params.domain ||
        params.status ||
        params.priority ||
        params.date_from ||
        params.date_to,
    );

    const handleReset = () => {
        setMany({
            search: '',
            domain: '',
            status: '',
            priority: '',
            date_from: '',
            date_to: '',
            page: 1,
        });
    };

    return (
        <form
            onSubmit={onSubmitSearch}
            className="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 md:flex-row md:flex-wrap md:items-end"
            role="search"
            aria-label="Filter pekerjaan saya"
        >
            <div className="min-w-56 flex-1">
                <Input
                    id="mywork-search"
                    label="Pencarian"
                    value={params.search ?? ''}
                    onChange={(event) => set('search', event.target.value)}
                    placeholder="Cari kode, judul, atau nama pelanggan..."
                    leftIcon={<Search className="h-4 w-4" />}
                    disabled={disabled}
                />
            </div>

            {domains.length > 1 && (
                <NativeSelect
                    id="mywork-domain"
                    label="Jenis"
                    wrapperClassName="w-full sm:w-44"
                    value={String(params.domain ?? '')}
                    onChange={(event) => set('domain', event.target.value)}
                    options={[
                        { value: '', label: 'Semua jenis' },
                        ...domains.map((d) => ({ value: d.value, label: d.label })),
                    ]}
                    disabled={disabled}
                />
            )}

            <NativeSelect
                id="mywork-status"
                label="Status"
                wrapperClassName="w-full sm:w-52"
                value={String(params.status ?? '')}
                onChange={(event) => set('status', event.target.value)}
                options={STATUS_OPTIONS}
                disabled={disabled}
            />

            <NativeSelect
                id="mywork-priority"
                label="Prioritas"
                wrapperClassName="w-full sm:w-48"
                value={String(params.priority ?? '')}
                onChange={(event) => set('priority', event.target.value)}
                options={[{ value: '', label: 'Semua prioritas' }, ...optionsOf(PRIORITY)]}
                disabled={disabled}
            />

            <div className="w-full sm:w-80">
                <DateRangePicker
                    id="mywork-date-range"
                    error={errors?.date_from ?? errors?.date_to}
                    label="Periode"
                    value={{ from: params.date_from || null, to: params.date_to || null }}
                    onChange={(range) =>
                        setMany({ date_from: range.from ?? '', date_to: range.to ?? '', page: 1 })
                    }
                    disabled={disabled}
                />
            </div>

            <div className="flex gap-2 pt-1 md:pt-0">
                <Button type="submit" variant="secondary" disabled={disabled}>
                    Cari
                </Button>
                {hasActiveFilters && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={handleReset}
                        disabled={disabled}
                        leftIcon={<X className="h-4 w-4" />}
                    >
                        Reset filter
                    </Button>
                )}
            </div>
        </form>
    );
}
