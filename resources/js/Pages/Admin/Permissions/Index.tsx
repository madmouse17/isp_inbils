import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Card, CardContent, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';

interface PermissionRow { id: number; name: string; group: string }
interface PermissionsProps { permissions: { data: PermissionRow[] } }

export default function Index({ permissions }: PermissionsProps) {
    return (
        <AdminLayout title="Permissions">
            <div className="space-y-6">
                <div>
                    <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">Permissions</h2>
                    <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">Read-only permission matrix.</p>
                </div>
                <Card>
                    <CardContent>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Name</TH>
                                    <TH>Group</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {permissions.data.map((permission) => (
                                    <TR key={permission.id}>
                                        <TD>{permission.name}</TD>
                                        <TD><Badge>{permission.group}</Badge></TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
