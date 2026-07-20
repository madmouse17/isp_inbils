import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import {
    Badge,
    Button,
    Card,
    CardContent,
    Input,
    Pagination,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';

interface UserRow {
    id: number;
    name: string;
    email: string;
    roles: string[];
    is_active: boolean;
}

interface PageLinkMeta {
    current_page: number;
    last_page: number;
}

interface UsersProps {
    users: { data: UserRow[]; meta: PageLinkMeta };
    can: { create: boolean };
}

export default function Index({ users, can }: UsersProps) {
    const [search, setSearch] = useState('');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('admin.users.index'), { search }, { preserveState: true });
    };

    const remove = (user: UserRow) => {
        if (window.confirm(`Delete ${user.name}?`))
            router.delete(route('admin.users.destroy', user.id));
    };

    return (
        <AdminLayout title="Users">
            <div className="space-y-6">
                <PageHeader
                    title="Users"
                    subtitle="Manage company users and roles."
                    actions={
                        can.create && (
                            <Button
                                type="button"
                                onClick={() => router.get(route('admin.users.create'))}
                            >
                                Create User
                            </Button>
                        )
                    }
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex gap-2">
                            <Input
                                label="Search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Name or email"
                            />
                            <div className="self-end">
                                <Button type="submit" variant="secondary">
                                    Filter
                                </Button>
                            </div>
                        </form>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Name</TH>
                                    <TH>Email</TH>
                                    <TH>Roles</TH>
                                    <TH>Status</TH>
                                    <TH>Actions</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {users.data.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={5}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No data found.
                                        </TD>
                                    </TR>
                                ) : (
                                    users.data.map((user) => (
                                        <TR key={user.id}>
                                            <TD>{user.name}</TD>
                                            <TD>{user.email}</TD>
                                            <TD>
                                                <div className="flex flex-wrap gap-1">
                                                    {user.roles.map((role) => (
                                                        <Badge key={role}>{role}</Badge>
                                                    ))}
                                                </div>
                                            </TD>
                                            <TD>
                                                <Badge
                                                    variant={user.is_active ? 'success' : 'danger'}
                                                >
                                                    {user.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </TD>
                                            <TD>
                                                <div className="flex flex-wrap gap-2">
                                                    <Link
                                                        href={route('admin.users.show', user.id)}
                                                        className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                                    >
                                                        Show
                                                    </Link>
                                                    <Link
                                                        href={route('admin.users.edit', user.id)}
                                                        className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                                    >
                                                        Edit
                                                    </Link>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => remove(user)}
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
                            currentPage={users.meta.current_page}
                            lastPage={users.meta.last_page}
                            onPageChange={(page) =>
                                router.get(route('admin.users.index'), { page })
                            }
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
