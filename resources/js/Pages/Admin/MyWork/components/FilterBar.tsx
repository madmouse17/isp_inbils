import type { FormEvent } from 'react';
import { MagnifyingGlassIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { NativeSelect } from '@/Components/composite/NativeSelect';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import type { ServerTableFilterValue } from '@/hooks/useServerTable';
import type { QueueDomainOption } from '@/types';

interface FilterBarProps {
    params: Record<string, string>;
    set: (key: string, value: ServerTableFilterValue) => void;
    setMany: (patch: Record<string, ServerTableFilterValue>) => void;
    domains: QueueDomainOption[];
    onSubmitSearch: (event: FormEvent) => void;
    disabled?: boolean;
}

export function FilterBar({
    params,
    set,
    setMany,
    domains,
    onSubmitSearch,
    disabled = false,
}: FilterBarProps) {
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
            className="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 shadow-sm md:flex-row md:flex-wrap md:items-end"
            role="search"
            aria-label="Filter Pekerjaan Saya"
        >
            <div className="min-w-[14rem] flex-1">
                <Input
                    id="mywork-search"
                    label="Pencarian"
                    value={params.search ?? ''}
                    onChange={(event) => set('search', event.target.value)}
                    placeholder="Cari kode, judul, atau nama pelanggan..."
                    leftIcon={<MagnifyingGlassIcon className="h-4 w-4" />}
                    disabled={disabled}
                />
            </div>

            {domains.length > 1 && (
                <div className="w-full sm:w-36">
                    <NativeSelect
                        id="mywork-domain"
                        label="Domain"
                        value={String(params.domain ?? '')}
                        onChange={(event) => set('domain', event.target.value)}
                        options={[
                            { value: '', label: 'Semua Domain' },
                            ...domains.map((d) => ({ value: d.value, label: d.label })),
                        ]}
                        disabled={disabled}
                    />
                </div>
            )}

            <div className="w-full sm:w-44">
                <NativeSelect
                    id="mywork-status"
                    label="Status Pekerjaan"
                    value={String(params.status ?? '')}
                    onChange={(event) => set('status', event.target.value)}
                    options={[
                        { value: '', label: 'Semua Status' },
                        { value: 'assigned', label: 'Belum Dimulai' },
                        { value: 'in_progress', label: 'Sedang Dikerjakan' },
                        { value: 'resolved', label: 'Selesai Ditangani' },
                    ]}
                    disabled={disabled}
                />
            </div>

            <div className="w-full sm:w-36">
                <NativeSelect
                    id="mywork-priority"
                    label="Prioritas"
                    value={String(params.priority ?? '')}
                    onChange={(event) => set('priority', event.target.value)}
                    options={[
                        { value: '', label: 'Semua Prioritas' },
                        { value: 'critical', label: 'Kritis' },
                        { value: 'high', label: 'Tinggi' },
                        { value: 'medium', label: 'Sedang' },
                        { value: 'low', label: 'Rendah' },
                    ]}
                    disabled={disabled}
                />
            </div>

            <div className="w-full sm:w-36">
                <Input
                    id="mywork-date-from"
                    label="Dari Tanggal"
                    type="date"
                    value={params.date_from ?? ''}
                    onChange={(event) => set('date_from', event.target.value)}
                    disabled={disabled}
                />
            </div>

            <div className="w-full sm:w-36">
                <Input
                    id="mywork-date-to"
                    label="Sampai Tanggal"
                    type="date"
                    value={params.date_to ?? ''}
                    onChange={(event) => set('date_to', event.target.value)}
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
                        aria-label="Atur ulang filter pekerjaan"
                    >
                        <XMarkIcon className="mr-1.5 h-4 w-4" />
                        Reset
                    </Button>
                )}
            </div>
        </form>
    );
}
