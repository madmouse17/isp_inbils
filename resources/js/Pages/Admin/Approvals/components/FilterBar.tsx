import type { FormEvent } from 'react';
import { Search, X } from 'lucide-react';
import { NativeSelect } from '@/Components/composite/NativeSelect';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { SearchSelect } from '@/Components/ui/SearchSelect';
import type { ServerTableFilterValue } from '@/hooks/useServerTable';
import { APPROVAL_TYPE, labelOf, optionsOf, PRIORITY } from '@/lib/statuses';

interface FilterBarProps {
    params: Record<string, string>;
    set: (key: string, value: ServerTableFilterValue) => void;
    setMany: (patch: Record<string, ServerTableFilterValue>) => void;
    types: string[];
    requesters: { id: string; name: string }[];
    onSubmitSearch: (event: FormEvent) => void;
    disabled?: boolean;
}

export function FilterBar({
    params,
    set,
    setMany,
    types,
    requesters,
    onSubmitSearch,
    disabled = false,
}: FilterBarProps) {
    const hasActiveFilters = Boolean(
        params.search || params.type || params.creator || params.priority,
    );

    const handleReset = () => {
        setMany({
            search: '',
            type: '',
            creator: '',
            priority: '',
        });
    };

    return (
        <form
            onSubmit={onSubmitSearch}
            className="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 md:flex-row md:flex-wrap md:items-end"
            role="search"
            aria-label="Filter antrean persetujuan"
        >
            <div className="min-w-56 flex-1">
                <Input
                    id="approvals-search"
                    label="Pencarian"
                    value={params.search ?? ''}
                    onChange={(event) => set('search', event.target.value)}
                    placeholder="Cari kode, judul, atau alasan..."
                    leftIcon={<Search className="h-4 w-4" />}
                    disabled={disabled}
                />
            </div>

            <NativeSelect
                id="approvals-type"
                label="Jenis"
                wrapperClassName="w-full sm:w-52"
                value={String(params.type ?? '')}
                onChange={(event) => set('type', event.target.value)}
                options={[
                    { value: '', label: 'Semua jenis' },
                    ...types.map((type) => ({ value: type, label: labelOf(APPROVAL_TYPE, type) })),
                ]}
                disabled={disabled}
            />

            <SearchSelect
                id="approvals-requester"
                label="Pemohon"
                className="w-full sm:w-56"
                value={String(params.creator ?? '')}
                onChange={(value) => set('creator', value)}
                options={requesters.map((requester) => ({
                    value: String(requester.id),
                    label: requester.name,
                }))}
                placeholder="Semua pemohon"
                emptyText="Tidak ada pemohon yang cocok."
                disabled={disabled}
            />

            <NativeSelect
                id="approvals-priority"
                label="Prioritas"
                wrapperClassName="w-full sm:w-48"
                value={String(params.priority ?? '')}
                onChange={(event) => set('priority', event.target.value)}
                options={[{ value: '', label: 'Semua prioritas' }, ...optionsOf(PRIORITY)]}
                disabled={disabled}
            />

            {hasActiveFilters && (
                <div className="shrink-0">
                    <Button
                        type="button"
                        variant="outline"
                        size="md"
                        onClick={handleReset}
                        disabled={disabled}
                        leftIcon={<X className="h-4 w-4" />}
                        className="w-full sm:w-auto"
                    >
                        Reset filter
                    </Button>
                </div>
            )}
        </form>
    );
}
