import { useMemo } from 'react';
import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { PageHeader } from '@/Components/composite';
import {
    Alert,
    AlertDescription,
    Button,
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    Checkbox,
    FileUpload,
    Input,
    NativeSelect,
    Progress,
    SearchSelect,
} from '@/Components/ui';

interface CreateProps {
    defaults: { timezone: string; currency: string };
    timezones: string[];
    currencies: string[];
}

export default function Create({ defaults, timezones, currencies }: CreateProps) {
    const form = useForm({
        name: '',
        code: '',
        logo: null as File | null,
        timezone: defaults.timezone,
        currency: defaults.currency,
        admin_name: '',
        admin_email: '',
        demo_data: false,
    });
    const timezoneOptions = useMemo(
        () => timezones.map((timezone) => ({ value: timezone, label: timezone })),
        [timezones],
    );
    const currencyOptions = currencies.map((currency) => ({ value: currency, label: currency }));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('platform.companies.store'), { forceFormData: true });
    };

    return (
        <PlatformLayout title="Perusahaan baru">
            <PageHeader
                title="Perusahaan baru"
                subtitle="Buat perusahaan dan akun admin pertamanya dalam satu proses."
                breadcrumbs={[
                    { label: 'Dasbor', href: route('platform.dashboard') },
                    { label: 'Perusahaan baru' },
                ]}
                actions={
                    <Button
                        asChild
                        variant="outline"
                        size="sm"
                        leftIcon={<ArrowLeft className="size-4" aria-hidden="true" />}
                    >
                        <Link href={route('platform.dashboard')}>Kembali</Link>
                    </Button>
                }
            />

            <form onSubmit={submit} className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Data perusahaan</CardTitle>
                        <CardDescription>
                            Kode perusahaan tidak dapat diubah setelah dibuat dan dipakai untuk
                            mengonfirmasi tindakan sensitif.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-5 sm:grid-cols-2">
                        <Input
                            id="tenant-name"
                            label="Nama perusahaan"
                            value={form.data.name}
                            maxLength={255}
                            error={form.errors.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            required
                        />
                        <Input
                            id="tenant-code"
                            label="Kode perusahaan"
                            value={form.data.code}
                            maxLength={50}
                            pattern="[A-Za-z0-9][A-Za-z0-9_\-]*"
                            hint="Gunakan huruf, angka, strip, atau garis bawah. Huruf besar dan kecil dibedakan."
                            error={form.errors.code}
                            onChange={(event) => form.setData('code', event.target.value)}
                            required
                        />
                        <SearchSelect
                            id="tenant-timezone"
                            label="Zona waktu"
                            value={form.data.timezone}
                            options={timezoneOptions}
                            placeholder="Cari zona waktu"
                            emptyText="Zona waktu tidak ditemukan."
                            error={form.errors.timezone}
                            onChange={(value) => form.setData('timezone', value)}
                            required
                        />
                        <NativeSelect
                            id="tenant-currency"
                            label="Mata uang"
                            value={form.data.currency}
                            options={currencyOptions}
                            error={form.errors.currency}
                            onChange={(event) => form.setData('currency', event.target.value)}
                            required
                        />
                        <FileUpload
                            label="Logo"
                            value={form.data.logo}
                            acceptedFileTypes={[
                                'image/jpeg',
                                'image/png',
                                'image/webp',
                                'image/svg+xml',
                            ]}
                            maxFileSize="10MB"
                            hint="Opsional. JPG, PNG, WebP, atau SVG hingga 10 MB."
                            error={form.errors.logo}
                            onChange={(file) => form.setData('logo', file)}
                            className="sm:col-span-2"
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Admin pertama</CardTitle>
                        <CardDescription>
                            Admin menerima email undangan untuk mengatur kata sandi sendiri.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-5 sm:grid-cols-2">
                        <Input
                            id="tenant-admin-name"
                            label="Nama admin"
                            value={form.data.admin_name}
                            maxLength={255}
                            error={form.errors.admin_name}
                            onChange={(event) => form.setData('admin_name', event.target.value)}
                            required
                        />
                        <Input
                            id="tenant-admin-email"
                            label="Email admin"
                            type="email"
                            value={form.data.admin_email}
                            maxLength={255}
                            autoComplete="off"
                            error={form.errors.admin_email}
                            onChange={(event) => form.setData('admin_email', event.target.value)}
                            required
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Data contoh</CardTitle>
                        <CardDescription>
                            Isi pelanggan, tagihan, SPK, tiket, dan inventaris contoh lewat antrean.
                            Tidak membuat perangkat jaringan atau pesan WhatsApp.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Checkbox
                            id="tenant-demo-data"
                            label="Isi data contoh"
                            checked={form.data.demo_data}
                            error={form.errors.demo_data}
                            onCheckedChange={(checked) =>
                                form.setData('demo_data', checked === true)
                            }
                        />
                    </CardContent>
                </Card>

                {Object.keys(form.errors).length > 0 ? (
                    <Alert variant="danger">
                        <AlertDescription>
                            Perusahaan belum dapat dibuat. Periksa isian yang ditandai.
                        </AlertDescription>
                    </Alert>
                ) : null}

                {form.processing && form.data.logo ? (
                    <Progress label="Mengunggah logo" value={form.progress?.percentage ?? 0} />
                ) : null}

                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button asChild variant="outline">
                        <Link href={route('platform.dashboard')}>Batal</Link>
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        Buat perusahaan
                    </Button>
                </div>
            </form>
        </PlatformLayout>
    );
}
