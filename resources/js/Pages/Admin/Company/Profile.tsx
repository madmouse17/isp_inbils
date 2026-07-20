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
    FileUpload,
    Input,
    Textarea,
    useToast,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import type { Company } from '@/types';

interface ProfileProps {
    company: { data: Company };
    can: { update: boolean };
}

export default function Profile({ company, can }: ProfileProps) {
    const { toast } = useToast();
    const { data, setData, post, processing, errors, reset } = useForm({
        _method: 'put',
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
        post(route('admin.company.profile.update'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset('logo');
                toast({ title: 'Company profile updated.', variant: 'success' });
            },
            onError: () => {
                toast({
                    title: 'Failed to update company profile.',
                    description: 'Check required fields and allowed file type.',
                    variant: 'danger',
                });
            },
        });
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
                            <FileUpload
                                label="Logo"
                                value={data.logo}
                                onChange={(file) => setData('logo', file)}
                                previewUrl={company.data.logo}
                                previewName={`${company.data.name} logo`}
                                acceptedFileTypes={[
                                    'image/jpeg',
                                    'image/png',
                                    'image/webp',
                                    'image/svg+xml',
                                ]}
                                hint="Max 10 MB. JPG, PNG, atau WebP akan diperkecil menjadi WebP; SVG disimpan sebagai SVG."
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
