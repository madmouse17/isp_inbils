import type { FormEvent } from 'react';
import { MagnifyingGlassIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { NativeSelect } from '@/Components/composite/NativeSelect';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import type { ServerTableFilterValue } from '@/hooks/useServerTable';

interface FilterBarProps {
    params: Record<string, string>;
    set: (key: string, value: ServerTableFilterValue) => void;
    setMany: (patch: Record<string, ServerTableFilterValue>) => void;
    types: string[];
    requesters: { id: number; name: string }[];
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
            className="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 shadow-xs md:flex-row md:items-end"
            role="search"
            aria-label="Filter Antrean Approval"
        >
            <div className="min-w-56 flex-1">
                <Input
                    id="approvals-search"
                    label="Pencarian"
                    value={params.search ?? ''}
                    onChange={(event) => set('search', event.target.value)}
                    placeholder="Cari kode, judul, atau alasan..."
                    leftIcon={<MagnifyingGlassIcon className="h-4 w-4" />}
                    disabled={disabled}
                />
            </div>

            <div className="w-full sm:w-44">
                <NativeSelect
                    id="approvals-type"
                    label="Tipe"
                    value={String(params.type ?? '')}
                    onChange={(event) => set('type', event.target.value)}
                    options={[
                        { value: '', label: 'Semua Tipe' },
                        ...types.map((type) => ({ value: type, label: type })),
                    ]}
                    disabled={disabled}
                />
            </div>

            <div className="w-full sm:w-48">
                <NativeSelect
                    id="approvals-requester"
                    label="Pemohon"
                    value={String(params.creator ?? '')}
                    onChange={(event) => set('creator', event.target.value)}
                    options={[
                        { value: '', label: 'Semua Pemohon' },
                        ...requesters.map((requester) => ({
                            value: String(requester.id),
                            label: requester.name,
                        })),
                    ]}
                    disabled={disabled}
                />
            </div>

            <div className="w-full sm:w-40">
                <NativeSelect
                    id="approvals-priority"
                    label="Prioritas"
                    value={String(params.priority ?? '')}
                    onChange={(event) => set('priority', event.target.value)}
                    options={[
                        { value: '', label: 'Semua Prioritas' },
                        { value: 'urgent', label: 'Urgent' },
                        { value: 'high', label: 'High' },
                        { value: 'medium', label: 'Medium' },
                        { value: 'low', label: 'Low' },
                    ]}
                    disabled={disabled}
                />
            </div>

            {hasActiveFilters && (
                <div className="shrink-0">
                    <Button
                        type="button"
                        variant="outline"
                        size="md"
                        onClick={handleReset}
                        disabled={disabled}
                        leftIcon={<XMarkIcon className="h-4 w-4" />}
                        className="w-full sm:w-auto"
                    >
                        Reset Filter
                    </Button>
                </div>
            )}
        </form>
    );
}
