import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, CardHeader, CardTitle } from '@/Components/ui';

interface ActivityItem { id: number; description: string; created_at: string }
interface UserData { id: number; name: string; email: string; company_id: number | null; is_active: boolean; roles: string[]; created_at?: string; last_login_at?: string | null; activity_log?: ActivityItem[] }
interface ShowProps { user: { data: UserData } }

export default function Show({ user }: ShowProps) {
    return (
        <AdminLayout title="User Detail">
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">{user.data.name}</h2>
                        <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">{user.data.email}</p>
                    </div>
                    <Button type="button" onClick={() => router.get(route('admin.users.edit', user.data.id))}>Edit</Button>
                </div>
                <Card>
                    <CardHeader><CardTitle>User Info</CardTitle></CardHeader>
                    <CardContent className="grid gap-4 text-sm md:grid-cols-2">
                        <p className="text-surface-700 dark:text-surface-300">Company ID: {user.data.company_id ?? '-'}</p>
                        <p><Badge variant={user.data.is_active ? 'success' : 'danger'}>{user.data.is_active ? 'Active' : 'Inactive'}</Badge></p>
                        <p className="text-surface-700 dark:text-surface-300">Created: {user.data.created_at ?? '-'}</p>
                        <p className="text-surface-700 dark:text-surface-300">Last login: {user.data.last_login_at ?? '-'}</p>
                        <div className="space-y-2 md:col-span-2">
                            <p className="font-medium text-surface-900 dark:text-surface-100">Roles</p>
                            <div className="flex flex-wrap gap-2">{user.data.roles.map((role) => <Badge key={role}>{role}</Badge>)}</div>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader><CardTitle>Activity Log</CardTitle></CardHeader>
                    <CardContent className="space-y-2 text-sm text-surface-600 dark:text-surface-400">
                        {(user.data.activity_log ?? []).length === 0 && <p>No activity available.</p>}
                        {(user.data.activity_log ?? []).map((item) => <p key={item.id}>{item.created_at} — {item.description}</p>)}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
