import { Card, CardContent } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import AdminLayout from '@/Layouts/AdminLayout';
import type { PageProps } from '@/types';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({
    mustVerifyEmail,
    status,
}: PageProps<{ mustVerifyEmail: boolean; status?: string }>) {
    return (
        <AdminLayout title="Profile">
            <div className="space-y-6">
                <PageHeader title="Profile" subtitle="Manage account information and security." />

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
                            <DeleteUserForm />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
