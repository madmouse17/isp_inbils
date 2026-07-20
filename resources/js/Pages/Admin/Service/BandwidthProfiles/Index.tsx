import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Badge,
    Button,
    Card,
    CardContent,
    Pagination,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface BwRow {
    id: number;
    name: string;
    download_mbps: number;
    upload_mbps: number;
    type: string;
    contention_ratio: number;
    is_active: boolean;
}

interface IndexProps extends Record<string, unknown> {
    bandwidthProfiles: { data: BwRow[]; current_page: number; last_page: number };
    can: { create: boolean };
}

export default function Index({ bandwidthProfiles, can }: IndexProps) {
    const remove = (b: BwRow) => {
        if (window.confirm(`Delete ${b.name}?`))
            router.delete(route('admin.bandwidth-profiles.destroy', b.id));
    };

    return (
        <AdminLayout title="Bandwidth Profiles">
            <div className="space-y-6">
                <PageHeader
                    title="Bandwidth Profiles"
                    subtitle="Manage bandwidth profiles for service packages."
                    actions={
                        can.create && (
                            <Button
                                type="button"
                                onClick={() => router.get(route('admin.bandwidth-profiles.create'))}
                            >
                                Create
                            </Button>
                        )
                    }
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Name</TH>
                                    <TH>Down</TH>
                                    <TH>Up</TH>
                                    <TH>Type</TH>
                                    <TH>Contention</TH>
                                    <TH>Status</TH>
                                    <TH>Actions</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {bandwidthProfiles.data.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={7}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No data found.
                                        </TD>
                                    </TR>
                                ) : (
                                    bandwidthProfiles.data.map((b) => (
                                        <TR key={b.id}>
                                            <TD>{b.name}</TD>
                                            <TD>{b.download_mbps} Mbps</TD>
                                            <TD>{b.upload_mbps} Mbps</TD>
                                            <TD>
                                                <Badge variant="neutral">{b.type}</Badge>
                                            </TD>
                                            <TD>1:{b.contention_ratio}</TD>
                                            <TD>
                                                <Badge variant={b.is_active ? 'success' : 'danger'}>
                                                    {b.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </TD>
                                            <TD>
                                                <div className="flex gap-2">
                                                    <Link
                                                        href={route(
                                                            'admin.bandwidth-profiles.edit',
                                                            b.id,
                                                        )}
                                                        className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                                    >
                                                        Edit
                                                    </Link>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => remove(b)}
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
                            currentPage={bandwidthProfiles.current_page}
                            lastPage={bandwidthProfiles.last_page}
                            onPageChange={(page) =>
                                router.get(route('admin.bandwidth-profiles.index'), { page })
                            }
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
