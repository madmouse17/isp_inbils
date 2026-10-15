import { Head, Link } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import AdminLayout from '@/Layouts/AdminLayout';
import GuestLayout from '@/Layouts/GuestLayout';
import type { PageProps } from '@/types';
import DeleteUserForm from './Partials/DeleteUserForm';
import TwoFactorAuthenticationForm, {
    type TwoFactorStatus,
} from './Partials/TwoFactorAuthenticationForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({
    mustVerifyEmail,
    status,
    twoFactor,
    appOnly,
}: PageProps<{ mustVerifyEmail: boolean; status?: string; twoFactor: TwoFactorStatus }>) {
    const sections = (
        <div className="grid gap-6 xl:grid-cols-2">
            <Card>
                <CardContent className="pt-6">
                    <UpdateProfileInformationForm
                        mustVerifyEmail={mustVerifyEmail}
                        status={status}
                    />
                </CardContent>
            </Card>

            <Card>
                <CardContent className="pt-6">
                    <UpdatePasswordForm />
                </CardContent>
            </Card>

            <Card className="xl:col-span-2">
                <CardContent className="pt-6">
                    <TwoFactorAuthenticationForm twoFactor={twoFactor} />
                </CardContent>
            </Card>

            <Card className="xl:col-span-2">
                <CardContent className="pt-6">
                    <DeleteUserForm />
                </CardContent>
            </Card>
        </div>
    );

    // Technician and customer accounts have no admin shell: a sidebar of links that all bounce to /app-only is a trap.
    if (appOnly) {
        return (
            <GuestLayout>
                <Head title="Profil" />
                <main className="space-y-6">
                    <Link
                        href={route('app-only')}
                        className="text-sm font-medium text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                    >
                        Kembali ke halaman aplikasi
                    </Link>
                    <PageHeader title="Profil" subtitle="Kelola data akun dan keamanan." />
                    {sections}
                </main>
            </GuestLayout>
        );
    }

    return (
        <AdminLayout title="Profil">
            <div className="space-y-6">
                <PageHeader title="Profil" subtitle="Kelola data akun dan keamanan." />
                {sections}
            </div>
        </AdminLayout>
    );
}
