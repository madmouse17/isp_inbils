import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
    Input,
    Textarea,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import type { Company } from '@/types';

interface ProfileProps {
    company: { data: Company };
    can: { update: boolean };
}

export default function Profile({ company, can }: ProfileProps) {
    const { data, setData, put, processing, errors } = useForm({
        name: company.data.name ?? '',
        code: company.data.code ?? '',
        logo: null as File | null,
        address: company.data.address ?? '',
        phone: company.data.phone ?? '',
        email: company.data.email ?? '',
        website: company.data.website ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('admin.company.profile.update'), { forceFormData: true });
    };

    return (
        <AdminLayout title="Company Profile">
            <div className="space-y-6">
                <PageHeader
                    title="Company Profile"
                    subtitle="Identity and contact data."
                    actions={
                        <Link
                            href={route('admin.company.settings.edit')}
                            className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                        >
                            Settings
                        </Link>
                    }
                />

                <form onSubmit={submit}>
                    <Card>
                        <CardHeader>
                            <CardTitle>{company.data.name}</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                error={errors.name}
                                required
                                disabled={!can.update}
                            />
                            <Input
                                label="Code"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                error={errors.code}
                                required
                                disabled={!can.update}
                            />
                            <Input
                                label="Logo"
                                type="file"
                                onChange={(e) => setData('logo', e.target.files?.[0] ?? null)}
                                error={errors.logo}
                                disabled={!can.update}
                            />
                            <Input
                                label="Phone"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                error={errors.phone}
                                disabled={!can.update}
                            />
                            <Input
                                label="Email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                error={errors.email}
                                disabled={!can.update}
                            />
                            <Input
                                label="Website"
                                value={data.website}
                                onChange={(e) => setData('website', e.target.value)}
                                error={errors.website}
                                disabled={!can.update}
                            />
                            <Textarea
                                className="md:col-span-2"
                                label="Address"
                                value={data.address}
                                onChange={(e) => setData('address', e.target.value)}
                                error={errors.address}
                                disabled={!can.update}
                            />
                        </CardContent>
                        {can.update && (
                            <CardFooter className="justify-end">
                                <Button type="submit" loading={processing}>
                                    Save Profile
                                </Button>
                            </CardFooter>
                        )}
                    </Card>
                </form>
            </div>
        </AdminLayout>
    );
}
