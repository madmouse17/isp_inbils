import type { FormEvent } from 'react';
import { useMemo } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    SearchSelect,
    Switch,
    Textarea,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface OptRow {
    id: number;
    name: string;
}

interface PkgData {
    id: number;
    code: string;
    name: string;
    service_package_category_id: number;
    bandwidth_profile_id: number | null;
    speed_profile_id: number | null;
    sla_tier_id: number | null;
    price_mrc: string;
    price_otc: string;
    contract_min_months: number | null;
    description: string | null;
    is_active: boolean;
}

interface EditProps extends Record<string, unknown> {
    servicePackage: { data: PkgData };
    bandwidthProfiles: { data: OptRow[] };
    speedProfiles: { data: OptRow[] };
    slaTiers: { data: OptRow[] };
    packageCategories: { id: number; code: string; name: string; is_active: boolean }[];
}

const toOptions = (rows: OptRow[]) =>
    rows.map((row) => ({ value: String(row.id), label: row.name }));

export default function Edit({
    servicePackage,
    bandwidthProfiles,
    speedProfiles,
    slaTiers,
    packageCategories,
}: EditProps) {
    const p = servicePackage.data;
    const { data, setData, put, processing, errors } = useForm({
        code: p.code,
        name: p.name,
        service_package_category_id: String(p.service_package_category_id),
        bandwidth_profile_id: p.bandwidth_profile_id ? String(p.bandwidth_profile_id) : '',
        speed_profile_id: p.speed_profile_id ? String(p.speed_profile_id) : '',
        sla_tier_id: p.sla_tier_id ? String(p.sla_tier_id) : '',
        price_mrc: p.price_mrc,
        price_otc: p.price_otc,
        contract_min_months: String(p.contract_min_months ?? 0),
        description: p.description ?? '',
        is_active: p.is_active,
    });

    const categoryOptions = useMemo(
        () =>
            packageCategories.map((category) => ({
                value: String(category.id),
                label: category.is_active ? category.name : `${category.name} (nonaktif)`,
            })),
        [packageCategories],
    );
    const bandwidthOptions = useMemo(() => toOptions(bandwidthProfiles.data), [bandwidthProfiles]);
    const speedOptions = useMemo(() => toOptions(speedProfiles.data), [speedProfiles]);
    const slaOptions = useMemo(() => toOptions(slaTiers.data), [slaTiers]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        put(route('admin.service-packages.update', p.id));
    };

    return (
        <AdminLayout title="Ubah Paket Layanan">
            <div className="space-y-6">
                <PageHeader
                    title="Ubah Paket Layanan"
                    subtitle="Ubah data paket, lalu simpan."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.service-packages.show', p.id)}>Kembali</Link>
                        </Button>
                    }
                />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Data paket</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Kode"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                error={errors.code}
                                required
                            />
                            <Input
                                label="Nama"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                error={errors.name}
                                required
                            />
                            <SearchSelect
                                id="package-category"
                                label="Kategori"
                                value={data.service_package_category_id}
                                onChange={(value) => setData('service_package_category_id', value)}
                                options={categoryOptions}
                                placeholder="Pilih kategori"
                                error={errors.service_package_category_id}
                                required
                            />
                            <SearchSelect
                                id="package-bandwidth-profile"
                                label="Profil bandwidth"
                                value={data.bandwidth_profile_id}
                                onChange={(value) => setData('bandwidth_profile_id', value)}
                                options={bandwidthOptions}
                                placeholder="Pilih profil"
                                error={errors.bandwidth_profile_id}
                                required
                            />
                            <SearchSelect
                                id="package-speed-profile"
                                label="Profil kecepatan"
                                value={data.speed_profile_id}
                                onChange={(value) => setData('speed_profile_id', value)}
                                options={speedOptions}
                                placeholder="Pilih profil"
                                error={errors.speed_profile_id}
                                required
                            />
                            <SearchSelect
                                id="package-sla-tier"
                                label="Tingkat SLA"
                                value={data.sla_tier_id}
                                onChange={(value) => setData('sla_tier_id', value)}
                                options={slaOptions}
                                placeholder="Pilih tingkat"
                                error={errors.sla_tier_id}
                                required
                            />
                            <Input
                                label="Harga bulanan"
                                value={data.price_mrc}
                                onChange={(e) => setData('price_mrc', e.target.value)}
                                error={errors.price_mrc}
                                required
                            />
                            <Input
                                label="Harga pemasangan"
                                value={data.price_otc}
                                onChange={(e) => setData('price_otc', e.target.value)}
                                error={errors.price_otc}
                            />
                            <Input
                                label="Masa kontrak minimum (bulan)"
                                type="number"
                                value={data.contract_min_months}
                                onChange={(e) => setData('contract_min_months', e.target.value)}
                                error={errors.contract_min_months}
                            />
                            <div className="flex items-end">
                                <Switch
                                    label="Aktif"
                                    checked={data.is_active}
                                    onCheckedChange={(c) => setData('is_active', c)}
                                />
                            </div>
                            <div className="md:col-span-2">
                                <Textarea
                                    label="Deskripsi"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    error={errors.description}
                                    rows={3}
                                />
                            </div>
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button asChild variant="outline">
                            <Link href={route('admin.service-packages.index')}>Batal</Link>
                        </Button>
                        <Button type="submit" variant="primary" loading={processing}>
                            Simpan
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
