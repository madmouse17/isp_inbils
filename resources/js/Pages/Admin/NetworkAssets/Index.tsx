import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Badge,
    Button,
    Card,
    CardContent,
    Input,
    Select,
    Pagination,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { PageHeader, StatusBadge } from '@/Components/composite';

interface AssetRow {
    id: number;
    code: string;
    name: string;
    asset_type: string;
    serial_number?: string | null;
    status: string;
    location?: { name: string } | null;
}

interface LocRow {
    id: number;
    name: string;
    code: string;
}

interface IndexProps extends Record<string, unknown> {
    assets: { data: AssetRow[]; current_page: number; last_page: number };
    locations: { data: LocRow[] };
    filters: { asset_type?: string; status?: string; location_id?: string; search?: string };
    can: { create: boolean };
}

export default function Index({ assets, locations, filters, can }: IndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [assetType, setAssetType] = useState(filters.asset_type ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [locationId, setLocationId] = useState(filters.location_id ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            route('admin.network-assets.index'),
            { search, asset_type: assetType, status, location_id: locationId },
            { preserveState: true },
        );
    };

    const remove = (a: AssetRow) => {
        if (window.confirm(`Delete ${a.name}?`))
            router.delete(route('admin.network-assets.destroy', a.id));
    };

    return (
        <AdminLayout title="Network Assets">
            <div className="space-y-6">
                <PageHeader
                    title="Network Assets"
                    subtitle="Tracked network equipment."
                    actions={
                        <>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => router.get(route('admin.network-assets.trace'))}
                            >
                                Trace
                            </Button>
                            {can.create && (
                                <Button
                                    type="button"
                                    onClick={() => router.get(route('admin.network-assets.create'))}
                                >
                                    Create
                                </Button>
                            )}
                        </>
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex flex-wrap gap-2">
                            <Input
                                label="Search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Serial, MAC, IP, code"
                            />
                            <Select
                                label="Type"
                                value={assetType}
                                onChange={(e) => setAssetType(e.target.value)}
                            >
                                <option value="">All</option>
                                <option value="router">Router</option>
                                <option value="switch">Switch</option>
                                <option value="olt">OLT</option>
                                <option value="onu_ont">ONU/ONT</option>
                                <option value="radio">Radio</option>
                                <option value="antenna">Antenna</option>
                                <option value="fiber">Fiber</option>
                                <option value="odp">ODP</option>
                                <option value="odc">ODC</option>
                                <option value="rack">Rack</option>
                                <option value="power">Power</option>
                                <option value="other">Other</option>
                            </Select>
                            <Select
                                label="Status"
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                            >
                                <option value="">All</option>
                                <option value="available">Available</option>
                                <option value="installed">Installed</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="damaged">Damaged</option>
                                <option value="retired">Retired</option>
                            </Select>
                            <Select
                                label="Location"
                                value={locationId}
                                onChange={(e) => setLocationId(e.target.value)}
                            >
                                <option value="">All</option>
                                {locations.data.map((l) => (
                                    <option key={l.id} value={l.id}>
                                        {l.code} — {l.name}
                                    </option>
                                ))}
                            </Select>
                            <div className="self-end">
                                <Button type="submit" variant="secondary">
                                    Filter
                                </Button>
                            </div>
                        </form>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Code</TH>
                                    <TH>Name</TH>
                                    <TH>Type</TH>
                                    <TH>Serial</TH>
                                    <TH>Status</TH>
                                    <TH>Location</TH>
                                    <TH>Actions</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {assets.data.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={7}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No data found.
                                        </TD>
                                    </TR>
                                ) : (
                                    assets.data.map((a) => (
                                        <TR key={a.id}>
                                            <TD className="font-mono text-sm">{a.code}</TD>
                                            <TD>{a.name}</TD>
                                            <TD>
                                                <Badge variant="neutral">{a.asset_type}</Badge>
                                            </TD>
                                            <TD className="font-mono text-sm">
                                                {a.serial_number ?? '-'}
                                            </TD>
                                            <TD>
                                                <StatusBadge
                                                    variant={
                                                        a.status === 'available'
                                                            ? 'success'
                                                            : a.status === 'installed'
                                                              ? 'info'
                                                              : a.status === 'maintenance'
                                                                ? 'warning'
                                                                : a.status === 'damaged'
                                                                  ? 'danger'
                                                                  : 'muted'
                                                    }
                                                >
                                                    {a.status}
                                                </StatusBadge>
                                            </TD>
                                            <TD>{a.location?.name ?? '-'}</TD>
                                            <TD>
                                                <div className="flex gap-2">
                                                    <Link
                                                        href={route(
                                                            'admin.network-assets.show',
                                                            a.id,
                                                        )}
                                                        className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                                    >
                                                        Show
                                                    </Link>
                                                    <Link
                                                        href={route(
                                                            'admin.network-assets.edit',
                                                            a.id,
                                                        )}
                                                        className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                                    >
                                                        Edit
                                                    </Link>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => remove(a)}
                                                    >
                                                        Delete
                                                    </Button>
                                                </div>
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                        <Pagination
                            currentPage={assets.current_page}
                            lastPage={assets.last_page}
                            onPageChange={(page) =>
                                router.get(route('admin.network-assets.index'), { page })
                            }
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
