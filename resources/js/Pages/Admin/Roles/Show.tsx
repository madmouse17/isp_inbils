import { useMemo } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader, type PermissionOption } from '@/Components/composite';
import {
    formatGroup,
    formatPermission,
    groupPermissions,
} from '@/Components/composite/permissionGroups';
import {
    Alert,
    AlertDescription,
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/Components/ui';
import { formatNumber } from '@/lib/format';

interface RoleData {
    id: string;
    name: string;
    template_key: string | null;
    permissions: string[];
    users_count?: number;
}

interface ShowProps {
    role: { data: RoleData };
    permissions: { data: PermissionOption[] };
    can: { update: boolean; delete: boolean };
}

/** `CompanyRoleService::CUSTOMER_TEMPLATE`: the role every new customer account gets. */
const CUSTOMER_TEMPLATE = 'customer';

export default function Show({ role, permissions, can }: ShowProps) {
    const {
        name,
        template_key: templateKey,
        permissions: held,
        users_count: usersCount = 0,
    } = role.data;

    const groups = useMemo(
        () =>
            Object.entries(
                groupPermissions(permissions.data.filter((option) => held.includes(option.name))),
            ),
        [permissions.data, held],
    );

    return (
        <AdminLayout title={`Peran ${name}`}>
            <div className="space-y-6">
                <PageHeader
                    title={`Peran ${name}`}
                    subtitle="Hak akses yang dipegang peran ini. Berikan peran ke pengguna di menu Pengguna."
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.roles.index')}>Kembali</Link>
                            </Button>
                            {can.update ? (
                                <Button asChild variant="primary" size="sm">
                                    <Link href={route('admin.roles.edit', role.data.id)}>Ubah</Link>
                                </Button>
                            ) : null}
                        </>
                    }
                />

                {can.update ? null : (
                    <Alert variant="info" role="status">
                        <AlertDescription>
                            Anda hanya bisa melihat peran ini, karena ia memuat hak akses yang tidak
                            Anda pegang. Hanya pemegang semua hak akses itu yang bisa mengubahnya.
                        </AlertDescription>
                    </Alert>
                )}
                {can.update && !can.delete && templateKey === CUSTOMER_TEMPLATE ? (
                    <Alert variant="info" role="status">
                        <AlertDescription>
                            Peran ini tidak bisa dihapus: setiap akun pelanggan baru mendapatkannya.
                        </AlertDescription>
                    </Alert>
                ) : null}

                <Card>
                    <CardHeader>
                        <CardTitle>Ringkasan</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-3">
                            <div>
                                <dt className="text-muted-foreground">Nama</dt>
                                <dd className="font-medium text-foreground">{name}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Hak akses</dt>
                                <dd className="font-medium text-foreground">
                                    {formatNumber(held.length)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Pengguna di perusahaan ini
                                </dt>
                                <dd className="font-medium text-foreground">
                                    {formatNumber(usersCount)}
                                </dd>
                            </div>
                        </dl>
                        <p className="mt-4 text-sm text-muted-foreground">
                            Peran ini hanya berlaku di perusahaan ini.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Hak akses peran</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {groups.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Peran ini belum memegang hak akses apa pun. Pengguna yang hanya
                                memegang peran ini tidak bisa membuka menu apa pun.
                            </p>
                        ) : (
                            <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                                {groups.map(([group, items]) => (
                                    <section key={group} aria-labelledby={`group-${group}`}>
                                        <h3
                                            id={`group-${group}`}
                                            className="mb-2 flex items-center gap-2 text-sm font-semibold"
                                        >
                                            {formatGroup(group)}
                                            <Badge variant="secondary">{items.length}</Badge>
                                        </h3>
                                        <ul className="space-y-1 text-sm">
                                            {items.map((item) => (
                                                <li key={item.name} title={item.name}>
                                                    {formatPermission(item.name)}
                                                </li>
                                            ))}
                                        </ul>
                                    </section>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
