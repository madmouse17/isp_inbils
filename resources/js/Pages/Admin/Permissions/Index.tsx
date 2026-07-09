import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Badge,
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

interface PermissionRow {
    id: number;
    name: string;
    group: string;
}
interface PageLinkMeta {
    current_page: number;
    last_page: number;
}
interface PermissionsProps {
    permissions: { data: PermissionRow[]; meta: PageLinkMeta };
}

export default function Index({ permissions }: PermissionsProps) {
    return (
        <AdminLayout title="Permissions">
            <div className="space-y-6">
                <PageHeader title="Permissions" subtitle="Read-only permission matrix." />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Name</TH>
                                    <TH>Group</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {permissions.data.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={2}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No data found.
                                        </TD>
                                    </TR>
                                ) : (
                                    permissions.data.map((permission) => (
                                        <TR key={permission.id}>
                                            <TD>{permission.name}</TD>
                                            <TD>
                                                <Badge>{permission.group}</Badge>
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                        <Pagination
                            currentPage={permissions.meta.current_page}
                            lastPage={permissions.meta.last_page}
                            onPageChange={(page) =>
                                router.get(route('admin.permissions.index'), { page })
                            }
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
