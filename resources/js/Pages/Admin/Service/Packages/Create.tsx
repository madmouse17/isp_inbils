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
import { MoneyInput, PageHeader } from '@/Components/composite';
import { SuggestionChip } from '@/Components/composite/SuggestionChip';
import { suggestCode } from '@/lib/suggestCode';
import { PackageProfilePickers, type ProfileRow } from './components/PackageProfilePickers';

interface CreateProps extends Record<string, unknown> {
    bandwidthProfiles: { data: ProfileRow[] };
    speedProfiles: { data: ProfileRow[] };
    slaTiers: { data: ProfileRow[] };
    packageCategories: { id: string; code: string; name: string; is_active: boolean }[];
}

const toOptions = (rows: { id: string; name: string }[]) =>
    rows.map((row) => ({ value: String(row.id), label: row.name }));

export default function Create({
    bandwidthProfiles,
    speedProfiles,
    slaTiers,
    packageCategories,
}: CreateProps) {
    const { data, setData, post, processing, errors } = useForm({
        code: '',
        name: '',
        service_package_category_id: '',
        bandwidth_profile_id: '',
        speed_profile_id: '',
        sla_tier_id: '',
        price_mrc: '',
        price_otc: '0',
        contract_min_months: '0',
        description: '',
        is_active: true,
    });

    const categoryOptions = useMemo(() => toOptions(packageCategories), [packageCategories]);
    const suggestedCode = suggestCode(data.name, 'PKG');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.service-packages.store'));
    };

    return (
        <AdminLayout title="Buat Paket Layanan">
            <div className="space-y-6">
                <PageHeader
                    title="Buat Paket Layanan"
                    subtitle="Isi data paket, lalu simpan."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.service-packages.index')}>Kembali</Link>
                        </Button>
                    }
                />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Data paket</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Input
                                    label="Kode"
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value)}
                                    error={errors.code}
                                    required
                                />
                                {data.code === '' && suggestedCode !== null && (
                                    <SuggestionChip
                                        value={suggestedCode}
                                        onUse={() => setData('code', suggestedCode)}
                                    />
                                )}
                            </div>
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
                            <PackageProfilePickers
                                values={data}
                                onChange={(key, value) => setData(key, value)}
                                errors={errors}
                                bandwidthProfiles={bandwidthProfiles.data}
                                speedProfiles={speedProfiles.data}
                                slaTiers={slaTiers.data}
                            />
                            <MoneyInput
                                id="package-price-mrc"
                                label="Harga bulanan"
                                value={data.price_mrc === '' ? null : Number(data.price_mrc)}
                                onChange={(value) =>
                                    setData('price_mrc', value === null ? '' : String(value))
                                }
                                error={errors.price_mrc}
                                required
                            />
                            <MoneyInput
                                id="package-price-otc"
                                label="Harga pemasangan"
                                value={data.price_otc === '' ? null : Number(data.price_otc)}
                                onChange={(value) =>
                                    setData('price_otc', value === null ? '' : String(value))
                                }
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
