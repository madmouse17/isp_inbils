import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
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

interface RoleRow {
    id: number;
    name: string;
    permissions: string[];
    users_count?: number;
}

interface PageLinkMeta {
    current_page: number;
    last_page: number;
}
interface RolesProps {
    roles: { data: RoleRow[]; meta: PageLinkMeta };
    can: { create: boolean };
}

const protectedRoles = ['admin', 'manager', 'staff', 'technician', 'customer'];

export default function Index({ roles, can }: RolesProps) {
    const remove = (role: RoleRow) => {
        if (window.confirm(`Delete ${role.name}?`))
            router.delete(route('admin.roles.destroy', role.id));
    };

    return (
        <AdminLayout title="Roles">
            <div className="space-y-6">
                <PageHeader
                    title="Roles"
                    subtitle="Manage RBAC roles and permission sets."
                    actions={
                        can.create && (
                            <Button
                                type="button"
                                onClick={() => router.get(route('admin.roles.create'))}
                            >
                                Create Role
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
                                    <TH>Permissions</TH>
                                    <TH>Users</TH>
                                    <TH>Actions</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {roles.data.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={4}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No data found.
                                        </TD>
                                    </TR>
                                ) : (
                                    roles.data.map((role) => {
                                        const protectedRole = protectedRoles.includes(role.name);
                                        return (
                                            <TR key={role.id}>
                                                <TD>
                                                    <div className="flex items-center gap-2">
                                                        {role.name}
                                                        {protectedRole && (
                                                            <Badge variant="warning">
                                                                Protected
                                                            </Badge>
                                                        )}
                                                    </div>
                                                </TD>
                                                <TD>{role.permissions.length}</TD>
                                                <TD>{role.users_count ?? 0}</TD>
                                                <TD>
                                                    <div className="flex flex-wrap gap-2">
                                                        <Link
                                                            href={route(
                                                                'admin.roles.edit',
                                                                role.id,
                                                            )}
                                                            className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                                        >
                                                            Edit
                                                        </Link>
                                                        {!protectedRole && (
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => remove(role)}
                                                            >
                                                                Delete
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TD>
                                            </TR>
                                        );
                                    })
                                )}
                            </TBody>
                        </Table>
                        <Pagination
                            currentPage={roles.meta.current_page}
                            lastPage={roles.meta.last_page}
                            onPageChange={(page) =>
                                router.get(route('admin.roles.index'), { page })
                            }
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
