import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import axios from 'axios';
import { ArrowLeft, Info } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { suggestCode } from '@/lib/suggestCode';
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
    Radio,
    RadioGroup,
    SearchSelect,
} from '@/Components/ui';

interface CreateProps {
    defaults: { timezone: string; currency: string };
    timezones: string[];
    currencies: string[];
}

type Catalog = 'starter' | 'empty';

const CATALOG_OPTIONS: ReadonlyArray<{ value: Catalog; label: string; description: string }> = [
    {
        value: 'starter',
        label: 'Katalog awal',
        description:
            'Paket, profil kecepatan, tingkat SLA, kategori tiket, barang dan lokasi contoh yang bisa diubah. Tanpa stok dan tanpa aset.',
    },
    {
        value: 'empty',
        label: 'Kosong',
        description: 'Hanya satuan barang, kategori paket, dan kategori tiket.',
    },
];

export default function Create({ defaults, timezones, currencies }: CreateProps) {
    const form = useForm({
        name: '',
        code: '',
        logo: null as File | null,
        timezone: defaults.timezone,
        currency: defaults.currency,
        admin_name: '',
        admin_email: '',
        catalog: 'starter' as Catalog,
        demo_data: false,
    });
    // The code follows the name until somebody types their own; then it stays theirs.
    const codeTyped = useRef(false);
    const [codeTaken, setCodeTaken] = useState(false);

    useEffect(() => {
        const code = form.data.code.trim();
        let current = true;
        // Only an answer for the code now in the field counts, so a slow reply for an older one is dropped.
        const timer = setTimeout(() => {
            if (!/^[A-Za-z0-9][A-Za-z0-9_-]*$/.test(code)) {
                setCodeTaken(false);
                return;
            }

            axios
                .get<{ available: boolean }>(route('platform.companies.code-check'), {
                    params: { code },
                })
                .then(({ data }) => current && setCodeTaken(!data.available))
                // The server checks the code again when the form is sent.
                .catch(() => current && setCodeTaken(false));
        }, 400);

        return () => {
            current = false;
            clearTimeout(timer);
        };
    }, [form.data.code]);
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
                            onChange={(event) => {
                                const name = event.target.value;
                                form.setData((data) => ({
                                    ...data,
                                    name,
                                    code: codeTyped.current ? data.code : (suggestCode(name) ?? ''),
                                }));
                            }}
                            required
                        />
                        <Input
                            id="tenant-code"
                            label="Kode perusahaan"
                            value={form.data.code}
                            maxLength={50}
                            pattern="[A-Za-z0-9][A-Za-z0-9_\-]*"
                            hint="Gunakan huruf, angka, strip, atau garis bawah. Huruf besar dan kecil dibedakan."
                            error={form.errors.code ?? (codeTaken ? 'Kode sudah dipakai' : undefined)}
                            onChange={(event) => {
                                codeTyped.current = event.target.value !== '';
                                form.setData('code', event.target.value);
                            }}
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
                        <CardTitle>Isi awal</CardTitle>
                        <CardDescription>
                            Data bawaan yang langsung ada di perusahaan baru. Admin bisa mengubahnya
                            kapan saja.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <RadioGroup
                            name="catalog"
                            label="Isi awal"
                            hideLabel
                            value={form.data.catalog}
                            error={form.errors.catalog}
                            onChange={(value) =>
                                form.setData('catalog', value === 'empty' ? 'empty' : 'starter')
                            }
                        >
                            {CATALOG_OPTIONS.map((option) => (
                                <Radio
                                    key={option.value}
                                    value={option.value}
                                    label={option.label}
                                    description={option.description}
                                    disabled={form.data.demo_data && option.value === 'empty'}
                                />
                            ))}
                        </RadioGroup>
                        {form.data.demo_data ? (
                            <Alert variant="info" role="status">
                                <Info className="size-4" aria-hidden="true" />
                                <AlertDescription>
                                    Data contoh memakai Katalog awal, jadi pilihan Kosong tidak
                                    tersedia.
                                </AlertDescription>
                            </Alert>
                        ) : null}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Data contoh</CardTitle>
                        <CardDescription>
                            Isi pelanggan, tagihan, SPK, tiket, stok barang, dan aset jaringan
                            contoh lewat antrean, di atas Katalog awal. Tidak membuat router NAS,
                            akun jaringan, atau pesan WhatsApp.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Checkbox
                            id="tenant-demo-data"
                            label="Isi data contoh"
                            checked={form.data.demo_data}
                            error={form.errors.demo_data}
                            onCheckedChange={(checked) =>
                                form.setData((data) => ({
                                    ...data,
                                    demo_data: checked === true,
                                    // Sample data stands on the starter catalog, so it takes the choice over.
                                    catalog: checked === true ? 'starter' : data.catalog,
                                }))
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
