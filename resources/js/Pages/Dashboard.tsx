import { Head } from '@inertiajs/react';
import { PlusIcon, UsersIcon, BoltIcon, BanknotesIcon, ClipboardDocumentListIcon } from '@heroicons/react/24/outline';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Breadcrumb,
    Alert,
    Button,
    StatCard,
    Card,
    CardHeader,
    CardTitle,
    CardDescription,
    CardContent,
    Table,
    THead,
    TBody,
    TR,
    TH,
    TD,
    Avatar,
    Badge,
    Pagination,
    Divider,
} from '@/Components/ui';

// ponytail: static demo data — swap for Inertia props from a DashboardController when backend is ready
const stats = [
    { label: 'Total Users', value: '1,284', delta: 12, deltaDirection: 'up' as const, icon: <UsersIcon className="h-6 w-6" /> },
    { label: 'Sesi Aktif', value: '342', delta: 5, deltaDirection: 'up' as const, icon: <BoltIcon className="h-6 w-6" /> },
    { label: 'Pendapatan', value: 'Rp 48.2M', delta: 8.4, deltaDirection: 'up' as const, icon: <BanknotesIcon className="h-6 w-6" /> },
    { label: 'Pesanan Pending', value: '23', delta: 3, deltaDirection: 'down' as const, icon: <ClipboardDocumentListIcon className="h-6 w-6" /> },
];

const recentUsers = [
    { name: 'Andi Saputra', email: 'andi@mail.com', role: 'Admin', status: 'Aktif', joined: '28 Jun 2026' },
    { name: 'Budi Santoso', email: 'budi@mail.com', role: 'User', status: 'Aktif', joined: '27 Jun 2026' },
    { name: 'Citra Dewi', email: 'citra@mail.com', role: 'Editor', status: 'Pending', joined: '26 Jun 2026' },
    { name: 'Dian Permata', email: 'dian@mail.com', role: 'User', status: 'Aktif', joined: '25 Jun 2026' },
    { name: 'Eko Prasetyo', email: 'eko@mail.com', role: 'User', status: 'Pending', joined: '24 Jun 2026' },
];

const activities = [
    { name: 'Andi Saputra', desc: 'Memperbarui profil pengguna', time: '5 menit lalu' },
    { name: 'Budi Santoso', desc: 'Membuat pesanan baru #1082', time: '12 menit lalu' },
    { name: 'Citra Dewi', desc: 'Mengunggah dokumen laporan', time: '1 jam lalu' },
    { name: 'Dian Permata', desc: 'Mengubah pengaturan notifikasi', time: '2 jam lalu' },
    { name: 'Eko Prasetyo', desc: 'Login dari perangkat baru', time: '3 jam lalu' },
];

export default function Dashboard() {
    return (
        <AdminLayout title="Dashboard">
            <Head title="Dashboard" />

            <div className="space-y-6 p-6 lg:p-8 max-w-7xl mx-auto">
                <Breadcrumb items={[{ label: 'Home', href: '/' }, { label: 'Dashboard' }]} />

                <Alert variant="info" title="Selamat datang, Admin">
                    Pantau aktivitas dan performa sistem dari halaman ini.
                </Alert>

                <div className="flex justify-between items-center">
                    <div>
                        <h1 className="text-2xl font-semibold text-surface-900 dark:text-surface-100">Dashboard</h1>
                        <p className="text-sm text-surface-500 dark:text-surface-400 mt-0.5">Ringkasan aktivitas hari ini</p>
                    </div>
                    <Button variant="primary" leftIcon={<PlusIcon className="h-4 w-4" />}>Tambah Laporan</Button>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    {stats.map((s) => (
                        <StatCard key={s.label} {...s} />
                    ))}
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div className="lg:col-span-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Pengguna Terbaru</CardTitle>
                                <CardDescription>5 pengguna terakhir</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Table>
                                    <THead>
                                        <TR>
                                            <TH>Pengguna</TH>
                                            <TH>Role</TH>
                                            <TH>Status</TH>
                                            <TH>Bergabung</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {recentUsers.map((u) => (
                                            <TR key={u.email}>
                                                <TD>
                                                    <div className="flex items-center gap-3">
                                                        <Avatar name={u.name} size="sm" />
                                                        <div>
                                                            <p className="font-medium text-surface-900 dark:text-surface-100">{u.name}</p>
                                                            <p className="text-xs text-surface-500">{u.email}</p>
                                                        </div>
                                                    </div>
                                                </TD>
                                                <TD>
                                                    <Badge variant={u.role === 'Admin' ? 'brand' : 'neutral'}>{u.role}</Badge>
                                                </TD>
                                                <TD>
                                                    <Badge variant={u.status === 'Aktif' ? 'success' : 'warning'}>{u.status}</Badge>
                                                </TD>
                                                <TD>
                                                    <span className="text-surface-500 dark:text-surface-400 text-sm">{u.joined}</span>
                                                </TD>
                                            </TR>
                                        ))}
                                    </TBody>
                                </Table>
                                <div className="mt-4">
                                    <Pagination currentPage={1} lastPage={3} onPageChange={() => {}} />
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle>Aktivitas Terbaru</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {activities.map((a, i) => (
                                <div key={i}>
                                    {i > 0 && <Divider />}
                                    <div className="flex items-start gap-3 py-3">
                                        <Avatar name={a.name} size="sm" />
                                        <div className="flex flex-col">
                                            <p className="text-sm text-surface-700 dark:text-surface-300">
                                                <span className="font-medium">{a.name}</span> {a.desc}
                                            </p>
                                            <p className="text-xs text-surface-500">{a.time}</p>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
