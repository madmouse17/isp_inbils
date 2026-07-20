import type { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    Select,
    Switch,
    Textarea,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        code: '',
        name: '',
        type: 'Individual' as 'Individual' | 'Company',
        email: '',
        phone: '',
        tax_id: '',
        contact_person: '',
        notes: '',
        is_active: true,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.customers.store'));
    };

    return (
        <AdminLayout title="Create Customer">
            <div className="space-y-6">
                <PageHeader title="Create Customer" subtitle="Fill required fields, then save." />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Code"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                error={errors.code}
                                required
                                placeholder="CUS-2026-00001"
                            />
                            <Input
                                label="Name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                error={errors.name}
                                required
                            />
                            <Select
                                label="Type"
                                value={data.type}
                                onChange={(e) =>
                                    setData('type', e.target.value as 'Individual' | 'Company')
                                }
                                error={errors.type}
                            >
                                <option value="Individual">Individual</option>
                                <option value="Company">Company</option>
                            </Select>
                            <Input
                                label="Email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                error={errors.email}
                                required
                            />
                            <Input
                                label="Phone"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                error={errors.phone}
                                hint="Password user customer sama dengan nomor phone."
                                required
                            />
                            <Input
                                label="Tax ID (NPWP)"
                                value={data.tax_id}
                                onChange={(e) => setData('tax_id', e.target.value)}
                                error={errors.tax_id}
                                hint="Wajib untuk Company, opsional untuk Individual."
                                required={data.type === 'Company'}
                            />
                            <Input
                                label="Contact Person"
                                value={data.contact_person}
                                onChange={(e) => setData('contact_person', e.target.value)}
                                error={errors.contact_person}
                            />
                            <div className="flex items-end">
                                <Switch
                                    label="Active"
                                    checked={data.is_active}
                                    onCheckedChange={(checked) => setData('is_active', checked)}
                                />
                            </div>
                            <Textarea
                                className="md:col-span-2"
                                label="Notes"
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                error={errors.notes}
                                rows={3}
                            />
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get(route('admin.customers.index'))}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" loading={processing}>
                            Create
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
