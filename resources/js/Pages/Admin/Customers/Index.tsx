import { useCallback, useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { SlidersHorizontal } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    CustomerAcquirerValue,
    DataTable,
    DataTableActions,
    NativeSelect,
    PageHeader,
    type DataTableColumn,
} from '@/Components/composite';
import {
    Badge,
    Button,
    Card,
    CardContent,
    EmptyCell,
    EmptyState,
    EnumBadge,
    Input,
    Pagination,
    SearchSelect,
    Skeleton,
    type SearchSelectOption,
} from '@/Components/ui';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/Components/ui/sheet';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { CUSTOMER_STATUS, CUSTOMER_TYPE, optionsOf } from '@/lib/statuses';
import type { Paginated } from '@/types';
import type { AcquirerOption, CustomerAcquirer } from '@/types/models';

interface CustomerRow {
    id: string;
    code: string;
    name: string;
    type: string;
    phone?: string | null;
    is_active: boolean;
    customer_status: string;
    addresses_count?: number;
    subscriptions_count?: number;
    acquired_by_user_id?: string | null;
    acquirer?: CustomerAcquirer | null;
}

interface IndexProps {
    customers: Paginated<CustomerRow>;
    filters: {
        type?: string;
        status?: string;
        account?: string;
        acquired_by?: string;
        search?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    acquirerOptions: AcquirerOption[];
    can: {
        create: boolean;
        export: boolean;
        update: boolean;
        delete: boolean;
        viewAcquirer: boolean;
    };
}

const NO_FILTERS = { search: '', type: '', status: '', account: '', acquired_by: '' };

const TYPE_OPTIONS = [{ value: '', label: 'Semua tipe' }, ...optionsOf(CUSTOMER_TYPE)];
const STATUS_OPTIONS = [{ value: '', label: 'Semua status' }, ...optionsOf(CUSTOMER_STATUS)];
const ACCOUNT_OPTIONS = [
    { value: '', label: 'Semua akun' },
    { value: 'active', label: 'Aktif' },
    { value: 'inactive', label: 'Nonaktif' },
];

interface FilterFieldsProps {
    /** The inline filters and the phone sheet are both in the DOM, so each set needs its own ids. */
    idPrefix: string;
    params: Record<string, string>;
    set: (key: string, value: string) => void;
    /** `null` when the viewer may not see who acquired a customer. */
    acquirers: SearchSelectOption[] | null;
    className?: string;
}

function FilterFields({ idPrefix, params, set, acquirers, className }: FilterFieldsProps) {
    return (
        <>
            <NativeSelect
                id={`${idPrefix}-type`}
                label="Tipe"
                value={params.type ?? ''}
                onChange={(event) => set('type', event.target.value)}
                options={TYPE_OPTIONS}
                wrapperClassName={className}
            />
            <NativeSelect
                id={`${idPrefix}-status`}
                label="Status"
                value={params.status ?? ''}
                onChange={(event) => set('status', event.target.value)}
                options={STATUS_OPTIONS}
                wrapperClassName={className}
            />
            <NativeSelect
                id={`${idPrefix}-account`}
                label="Akun"
                value={params.account ?? ''}
                onChange={(event) => set('account', event.target.value)}
                options={ACCOUNT_OPTIONS}
                wrapperClassName={className}
            />
            {acquirers ? (
                <SearchSelect
                    id={`${idPrefix}-acquired-by`}
                    label="Diperoleh oleh"
                    value={params.acquired_by ?? ''}
                    onChange={(value) => set('acquired_by', value)}
                    options={acquirers}
                    placeholder="Semua"
                    className={className}
                />
            ) : null}
        </>
    );
}

export default function Index({ customers, filters, acquirerOptions, can }: IndexProps) {
    const [filterSheetOpen, setFilterSheetOpen] = useState(false);
    const { params, search, setSearch, set, setMany, sortBy, sortDir, onSort, processing } =
        useServerTable({
            url: route('admin.customers.index'),
            filters,
            only: ['customers', 'filters', 'can', 'acquirerOptions'],
        });

    const acquirers = useMemo<SearchSelectOption[] | null>(
        () =>
            can.viewAcquirer
                ? [
                      { value: 'unassigned', label: 'Belum ditentukan' },
                      ...acquirerOptions.map((option) => ({
                          value: String(option.id),
                          label: option.is_active ? option.name : `${option.name} (nonaktif)`,
                      })),
                  ]
                : null,
        [acquirerOptions, can.viewAcquirer],
    );

    const hasFilters = Object.keys(NO_FILTERS).some((key) => params[key]);
    const sheetFilterCount = ['type', 'status', 'account', 'acquired_by'].filter(
        (key) => params[key],
    ).length;
    const resetFilters = () => setMany(NO_FILTERS);

    const createButton = can.create ? (
        <Button asChild variant="primary">
            <Link href={route('admin.customers.create')}>Buat Pelanggan</Link>
        </Button>
    ) : undefined;

    const rowActions = useCallback(
        (customer: CustomerRow) => (
            <DataTableActions
                showHref={route('admin.customers.show', customer.id)}
                editHref={can.update ? route('admin.customers.edit', customer.id) : undefined}
                deleteHref={can.delete ? route('admin.customers.destroy', customer.id) : undefined}
                deleteMessage={`Hapus ${customer.name}?`}
                deleteDescription="Pelanggan yang masih punya langganan belum berhenti tidak bisa dihapus."
            />
        ),
        [can.delete, can.update],
    );

    const columns: DataTableColumn<CustomerRow>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'Kode',
                sortable: true,
                sortKey: 'code',
                cell: (customer) => (
                    <span className="font-mono text-sm whitespace-nowrap">{customer.code}</span>
                ),
            },
            {
                key: 'name',
                header: 'Nama',
                sortable: true,
                sortKey: 'name',
                cell: (customer) => (
                    <Link
                        href={route('admin.customers.show', customer.id)}
                        className="font-medium text-primary hover:underline"
                    >
                        {customer.name}
                    </Link>
                ),
            },
            {
                key: 'type',
                header: 'Tipe',
                sortable: true,
                sortKey: 'type',
                cell: (customer) => <EnumBadge map={CUSTOMER_TYPE} value={customer.type} />,
            },
            {
                key: 'phone',
                header: 'Telepon',
                sortable: true,
                sortKey: 'phone',
                cell: (customer) =>
                    customer.phone ? (
                        <span className="whitespace-nowrap">{customer.phone}</span>
                    ) : (
                        <EmptyCell />
                    ),
            },
            {
                key: 'customer_status',
                header: 'Status',
                sortable: true,
                sortKey: 'customer_status',
                cell: (customer) => (
                    <div className="flex flex-wrap items-center gap-1.5">
                        <EnumBadge map={CUSTOMER_STATUS} value={customer.customer_status} />
                        {customer.is_active ? null : <Badge variant="muted">Akun nonaktif</Badge>}
                    </div>
                ),
            },
            ...(can.viewAcquirer
                ? [
                      {
                          key: 'acquirer',
                          header: 'Diperoleh oleh',
                          cell: (customer: CustomerRow) => (
                              <CustomerAcquirerValue acquirer={customer.acquirer} />
                          ),
                      },
                  ]
                : []),
            {
                key: 'addresses_count',
                header: 'Alamat',
                headerClassName: 'text-right',
                className: 'text-right tabular-nums',
                cell: (customer) => customer.addresses_count ?? 0,
            },
            {
                key: 'subscriptions_count',
                header: 'Langganan',
                headerClassName: 'text-right',
                className: 'text-right tabular-nums',
                cell: (customer) => customer.subscriptions_count ?? 0,
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: rowActions,
            },
        ],
        [can.viewAcquirer, rowActions],
    );

    return (
        <AdminLayout title="Pelanggan">
            <div className="space-y-6">
                <PageHeader
                    title="Pelanggan"
                    subtitle="Cari pelanggan, cek status layanannya, lalu buka detail atau ubah datanya."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.customers.export')}
                                    params={params}
                                    formats={['csv', 'pdf']}
                                    canExport={can.export}
                                />
                            ) : null}
                            {createButton}
                        </div>
                    }
                />

                <div className="space-y-4">
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="min-w-56 flex-1">
                            <Input
                                id="customer-search"
                                label="Cari"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Nama, kode, atau telepon"
                            />
                        </div>
                        <div className="hidden flex-wrap items-end gap-3 md:flex">
                            <FilterFields
                                idPrefix="customer-filter"
                                params={params}
                                set={set}
                                acquirers={acquirers}
                                className="w-44"
                            />
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            className="md:hidden"
                            leftIcon={<SlidersHorizontal className="size-4" aria-hidden="true" />}
                            onClick={() => setFilterSheetOpen(true)}
                        >
                            {sheetFilterCount > 0 ? `Filter (${sheetFilterCount})` : 'Filter'}
                        </Button>
                        {hasFilters ? (
                            <Button type="button" variant="ghost" onClick={resetFilters}>
                                Reset filter
                            </Button>
                        ) : null}
                    </div>

                    <div className="hidden md:block">
                        <DataTable
                            columns={columns}
                            pagination={toPagination(customers)}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={onSort}
                            onPageChange={(page) => set('page', page)}
                            onPerPageChange={(perPage) => set('per_page', perPage)}
                            loading={processing}
                            itemLabel="pelanggan"
                            hasFilters={hasFilters}
                            onResetFilters={resetFilters}
                            emptyTitle="Belum ada pelanggan"
                            emptyDescription="Satu pelanggan dibuat bersama alamat, kontak, langganan, dan SPK pemasangan."
                            emptyAction={createButton}
                        />
                    </div>

                    <div className="space-y-3 md:hidden">
                        <p role="status" className="sr-only">
                            {processing ? 'Memuat pelanggan...' : ''}
                        </p>
                        {processing ? (
                            Array.from({ length: 3 }).map((_, index) => (
                                <Skeleton key={index} className="h-36 w-full" />
                            ))
                        ) : customers.data.length === 0 ? (
                            <Card>
                                <EmptyState
                                    title={
                                        hasFilters
                                            ? 'Tidak ada pelanggan yang cocok'
                                            : 'Belum ada pelanggan'
                                    }
                                    description={
                                        hasFilters
                                            ? 'Ubah kata kunci atau filter, atau kembalikan semuanya ke awal.'
                                            : 'Satu pelanggan dibuat bersama alamat, kontak, langganan, dan SPK pemasangan.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={resetFilters}
                                            >
                                                Reset filter
                                            </Button>
                                        ) : (
                                            createButton
                                        )
                                    }
                                />
                            </Card>
                        ) : (
                            customers.data.map((customer) => (
                                <Card key={customer.id}>
                                    <CardContent className="space-y-3 p-4">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="space-y-0.5 min-w-0">
                                                <Link
                                                    href={route(
                                                        'admin.customers.show',
                                                        customer.id,
                                                    )}
                                                    className="font-medium break-words text-primary hover:underline"
                                                >
                                                    {customer.name}
                                                </Link>
                                                <p className="font-mono text-xs whitespace-nowrap text-muted-foreground">
                                                    {customer.code}
                                                </p>
                                            </div>
                                            {rowActions(customer)}
                                        </div>
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <EnumBadge
                                                map={CUSTOMER_STATUS}
                                                value={customer.customer_status}
                                            />
                                            <EnumBadge map={CUSTOMER_TYPE} value={customer.type} />
                                            {customer.is_active ? null : (
                                                <Badge variant="muted">Akun nonaktif</Badge>
                                            )}
                                        </div>
                                        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                            <dt className="text-muted-foreground">Telepon</dt>
                                            <dd className="whitespace-nowrap">
                                                {customer.phone || <EmptyCell />}
                                            </dd>
                                            {can.viewAcquirer ? (
                                                <>
                                                    <dt className="text-muted-foreground">
                                                        Diperoleh oleh
                                                    </dt>
                                                    <dd>
                                                        <CustomerAcquirerValue
                                                            acquirer={customer.acquirer}
                                                        />
                                                    </dd>
                                                </>
                                            ) : null}
                                            <dt className="text-muted-foreground">Alamat</dt>
                                            <dd className="tabular-nums">
                                                {customer.addresses_count ?? 0}
                                            </dd>
                                            <dt className="text-muted-foreground">Langganan</dt>
                                            <dd className="tabular-nums">
                                                {customer.subscriptions_count ?? 0}
                                            </dd>
                                        </dl>
                                    </CardContent>
                                </Card>
                            ))
                        )}
                        <Pagination
                            meta={customers.meta}
                            onPageChange={(page) => set('page', page)}
                            disabled={processing}
                        />
                    </div>
                </div>
            </div>

            <Sheet open={filterSheetOpen} onOpenChange={setFilterSheetOpen}>
                <SheetContent side="bottom" className="max-h-[85dvh] overflow-y-auto">
                    <SheetHeader>
                        <SheetTitle>Filter pelanggan</SheetTitle>
                        <SheetDescription>Daftar langsung mengikuti pilihan Anda.</SheetDescription>
                    </SheetHeader>
                    <div className="space-y-4 py-4">
                        <FilterFields
                            idPrefix="customer-sheet-filter"
                            params={params}
                            set={set}
                            acquirers={acquirers}
                        />
                    </div>
                    <SheetFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={resetFilters}>
                            Reset filter
                        </Button>
                        <Button
                            type="button"
                            variant="primary"
                            onClick={() => setFilterSheetOpen(false)}
                        >
                            Lihat hasil
                        </Button>
                    </SheetFooter>
                </SheetContent>
            </Sheet>
        </AdminLayout>
    );
}
