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

interface OptRow {
    id: number;
    name: string;
}

interface CreateProps extends Record<string, unknown> {
    bandwidthProfiles: { data: OptRow[] };
    speedProfiles: { data: OptRow[] };
    slaTiers: { data: OptRow[] };
}

export default function Create({ bandwidthProfiles, speedProfiles, slaTiers }: CreateProps) {
    const { data, setData, post, processing, errors } = useForm({
        code: '',
        name: '',
        bandwidth_profile_id: '',
        speed_profile_id: '',
        sla_tier_id: '',
        price_mrc: '',
        price_otc: '0',
        contract_min_months: '',
        description: '',
        is_active: true,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.service-packages.store'));
    };

    return (
        <AdminLayout title="Create Service Package">
            <div className="space-y-6">
                <PageHeader
                    title="Create Service Package"
                    subtitle="Fill required fields, then save."
                />
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
                            />
                            <Input
                                label="Name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                error={errors.name}
                                required
                            />
                            <Select
                                label="Bandwidth Profile"
                                value={data.bandwidth_profile_id}
                                onChange={(e) => setData('bandwidth_profile_id', e.target.value)}
                                error={errors.bandwidth_profile_id}
                            >
                                <option value="">Select...</option>
                                {bandwidthProfiles.data.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                    </option>
                                ))}
                            </Select>
                            <Select
                                label="Speed Profile"
                                value={data.speed_profile_id}
                                onChange={(e) => setData('speed_profile_id', e.target.value)}
                                error={errors.speed_profile_id}
                            >
                                <option value="">Select...</option>
                                {speedProfiles.data.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </Select>
                            <Select
                                label="SLA Tier"
                                value={data.sla_tier_id}
                                onChange={(e) => setData('sla_tier_id', e.target.value)}
                                error={errors.sla_tier_id}
                            >
                                <option value="">Select...</option>
                                {slaTiers.data.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </Select>
                            <Input
                                label="Price MRC"
                                value={data.price_mrc}
                                onChange={(e) => setData('price_mrc', e.target.value)}
                                error={errors.price_mrc}
                                required
                            />
                            <Input
                                label="Price OTC"
                                value={data.price_otc}
                                onChange={(e) => setData('price_otc', e.target.value)}
                                error={errors.price_otc}
                            />
                            <Input
                                label="Contract Min Months"
                                type="number"
                                value={data.contract_min_months}
                                onChange={(e) => setData('contract_min_months', e.target.value)}
                                error={errors.contract_min_months}
                            />
                            <div className="flex items-end">
                                <Switch
                                    label="Active"
                                    checked={data.is_active}
                                    onCheckedChange={(c) => setData('is_active', c)}
                                />
                            </div>
                            <div className="md:col-span-2">
                                <Textarea
                                    label="Description"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    error={errors.description}
                                    rows={3}
                                />
                            </div>
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get(route('admin.service-packages.index'))}
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
