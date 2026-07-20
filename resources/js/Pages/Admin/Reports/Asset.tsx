import type { FormEvent } from 'react';
import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import {
    Button,
    Card,
    CardContent,
    Input,
    Select,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';

interface AssetData extends Record<string, unknown> {
    status_distribution?: Record<string, number>;
    by_location?: Record<string, number>;
    installation_count?: number;
    customer_linked_count?: number;
    retired_count?: number;
}

interface Props extends Record<string, unknown> {
    data?: AssetData;
    filters: { asset_type?: string; date_from?: string; date_to?: string };
}

export default function Asset({ data, filters }: Props) {
    const [assetType, setAssetType] = useState(filters.asset_type ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            route('admin.reports.asset'),
            { asset_type: assetType, date_from: dateFrom, date_to: dateTo },
            { preserveState: true },
        );
    };

    return (
        <AdminLayout title="Asset Utilization">
            <div className="space-y-6">
                <PageHeader
                    title="Asset Utilization"
                    subtitle="Status distribution, per-location, and installation history."
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex flex-wrap gap-2">
                            <Select
                                label="Asset Type"
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
                            </Select>
                            <Input
                                label="From"
                                type="date"
                                value={dateFrom}
                                onChange={(e) => setDateFrom(e.target.value)}
                            />
                            <Input
                                label="To"
                                type="date"
                                value={dateTo}
                                onChange={(e) => setDateTo(e.target.value)}
                            />
                            <div className="self-end">
                                <Button type="submit">Run</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
                {data && (
                    <div className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-3">
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-surface-500">Installations</p>
                                    <p className="text-2xl font-bold">
                                        {data.installation_count ?? 0}
                                    </p>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-surface-500">Customer-Linked</p>
                                    <p className="text-2xl font-bold">
                                        {data.customer_linked_count ?? 0}
                                    </p>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-surface-500">Retired</p>
                                    <p className="text-2xl font-bold">{data.retired_count ?? 0}</p>
                                </CardContent>
                            </Card>
                        </div>
                        <Card>
                            <CardContent>
                                <p className="mb-2 font-medium">Status Distribution</p>
                                <Table>
                                    <THead>
                                        <TR>
                                            <TH>Status</TH>
                                            <TH>Count</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {Object.entries(data.status_distribution ?? {}).length ===
                                        0 ? (
                                            <TR>
                                                <TD
                                                    colSpan={2}
                                                    className="py-10 text-center text-muted-foreground"
                                                >
                                                    No data found.
                                                </TD>
                                            </TR>
                                        ) : (
                                            Object.entries(data.status_distribution ?? {}).map(
                                                ([s, c]) => (
                                                    <TR key={s}>
                                                        <TD>{s}</TD>
                                                        <TD>{c}</TD>
                                                    </TR>
                                                ),
                                            )
                                        )}
                                    </TBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
