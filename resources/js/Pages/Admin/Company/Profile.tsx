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
    PhoneInput,
    Progress,
    Textarea,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import { MIN_TEXT_CONTRAST, contrastWithWhite } from '@/lib/contrast';
import type { Company } from '@/types';
import {
    SubsidiaryRequestCard,
    type SubsidiaryRequestSummary,
} from './components/SubsidiaryRequestCard';

interface ProfileProps {
    company: { data: Company };
    can: { update: boolean; requestSubsidiary: boolean };
    /** The company's open request for a subsidiary; only sent when the person may ask for one. */
    subsidiaryRequest: SubsidiaryRequestSummary | null;
}

const BRAND_PRESETS = [
    { hex: '#2563EB', name: 'Biru' },
    { hex: '#0F766E', name: 'Hijau tosca' },
    { hex: '#7C3AED', name: 'Ungu' },
    { hex: '#DB2777', name: 'Merah muda' },
    { hex: '#EA580C', name: 'Jingga' },
    { hex: '#16A34A', name: 'Hijau' },
    { hex: '#0891B2', name: 'Biru muda' },
    { hex: '#334155', name: 'Abu kebiruan' },
] as const;

interface BrandColorFieldProps {
    value: string;
    onChange: (value: string) => void;
    error?: string;
    disabled: boolean;
}

function BrandColorField({ value, onChange, error, disabled }: BrandColorFieldProps) {
    const ratio = contrastWithWhite(value);
    const readable = ratio !== null && ratio >= MIN_TEXT_CONTRAST;

    return (
        <div className="space-y-3 md:col-span-2">
            <fieldset className="space-y-2" disabled={disabled}>
                <legend className="text-sm leading-none font-medium">Warna merek</legend>
                <div role="radiogroup" aria-label="Warna merek" className="flex flex-wrap gap-3">
                    {BRAND_PRESETS.map((preset) => (
                        <label
                            key={preset.hex}
                            title={`${preset.name} ${preset.hex}`}
                            className="relative cursor-pointer has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-50"
                        >
                            <input
                                type="radio"
                                name="brand_color"
                                value={preset.hex}
                                checked={value.toUpperCase() === preset.hex}
                                onChange={() => onChange(preset.hex)}
                                aria-label={`${preset.name} ${preset.hex}`}
                                className="peer sr-only"
                            />
                            <span
                                aria-hidden="true"
                                style={{ backgroundColor: preset.hex }}
                                className="block h-9 w-9 rounded-full border border-border ring-offset-2 ring-offset-background peer-checked:ring-2 peer-checked:ring-foreground peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-4 peer-focus-visible:outline-ring"
                            />
                        </label>
                    ))}
                </div>
            </fieldset>
            <div className="grid items-start gap-3 sm:grid-cols-2">
                <Input
                    id="brand_color"
                    label="Kode warna"
                    value={value}
                    onChange={(e) => onChange(e.target.value.trim())}
                    placeholder="#2563EB"
                    maxLength={7}
                    hint="Format #RRGGBB, misalnya #2563EB. Kosongkan untuk menghapus warna merek."
                    error={error}
                    disabled={disabled}
                />
                {ratio !== null && (
                    <div className="flex items-center gap-3 pt-6" role="status">
                        <span
                            style={{ backgroundColor: value }}
                            className="rounded-md px-3 py-1.5 text-sm font-medium text-white"
                        >
                            Contoh teks putih
                        </span>
                        <p
                            className={
                                readable
                                    ? 'text-sm text-muted-foreground'
                                    : 'text-sm font-medium text-amber-700 dark:text-amber-400'
                            }
                        >
                            Kontras {ratio.toFixed(2).replace('.', ',')}:1.{' '}
                            {readable
                                ? 'Teks putih terbaca jelas.'
                                : 'Di bawah 4,5:1, teks putih sulit dibaca. Pilih warna lebih gelap.'}
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}

export default function Profile({ company, can, subsidiaryRequest }: ProfileProps) {
    const { data, setData, post, processing, progress, errors, reset } = useForm({
        _method: 'put',
        name: company.data.name ?? '',
        logo: null as File | null,
        address: company.data.address ?? '',
        phone: company.data.phone ?? '',
        email: company.data.email ?? '',
        website: company.data.website ?? '',
        whatsapp: company.data.whatsapp ?? '',
        support_hours: company.data.support_hours ?? '',
        brand_color: company.data.brand_color ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.company.profile.update'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => reset('logo'),
        });
    };

    return (
        <AdminLayout title="Perusahaan">
            <div className="space-y-6">
                <PageHeader
                    title="Perusahaan"
                    subtitle="Identitas dan kontak yang tampil di tagihan dan dokumen."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={route('admin.company.settings.edit')}>Pengaturan</Link>
                        </Button>
                    }
                />

                <form onSubmit={submit}>
                    <Card>
                        <CardHeader>
                            <CardTitle>{company.data.name}</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Nama"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                error={errors.name}
                                required
                                disabled={!can.update}
                            />
                            <Input
                                label="Kode"
                                value={company.data.code ?? ''}
                                hint="Kode perusahaan ditetapkan operator platform dan tidak dapat diubah di sini."
                                readOnly
                                disabled
                            />
                            <FileUpload
                                label="Logo"
                                value={data.logo}
                                onChange={(file) => setData('logo', file)}
                                previewUrl={company.data.logo}
                                previewName={`Logo ${company.data.name}`}
                                acceptedFileTypes={[
                                    'image/jpeg',
                                    'image/png',
                                    'image/webp',
                                    'image/svg+xml',
                                ]}
                                hint="Maksimal 10 MB. JPG, PNG, atau WebP diperkecil menjadi WebP; SVG disimpan apa adanya."
                                error={errors.logo}
                                disabled={!can.update}
                            />
                            <PhoneInput
                                label="Telepon"
                                value={data.phone}
                                onChange={(value) => setData('phone', value)}
                                hint="Hanya angka dan karakter ( ) + -."
                                error={errors.phone}
                                disabled={!can.update}
                            />
                            <PhoneInput
                                label="WhatsApp"
                                value={data.whatsapp}
                                onChange={(value) => setData('whatsapp', value)}
                                hint="Nomor tujuan tombol WhatsApp di aplikasi pelanggan. Hanya angka dan karakter ( ) + -."
                                error={errors.whatsapp}
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
                                label="Situs web"
                                value={data.website}
                                onChange={(e) => setData('website', e.target.value)}
                                placeholder="https://"
                                error={errors.website}
                                disabled={!can.update}
                            />
                            <Textarea
                                className="md:col-span-2"
                                label="Alamat"
                                value={data.address}
                                onChange={(e) => setData('address', e.target.value)}
                                error={errors.address}
                                disabled={!can.update}
                            />
                            <Input
                                label="Jam layanan"
                                value={data.support_hours}
                                onChange={(e) => setData('support_hours', e.target.value)}
                                placeholder="Senin sampai Sabtu, 08.00 sampai 17.00"
                                maxLength={120}
                                hint="Tampil di aplikasi pelanggan sebagai jam dukungan."
                                error={errors.support_hours}
                                disabled={!can.update}
                            />
                            <BrandColorField
                                value={data.brand_color}
                                onChange={(value) => setData('brand_color', value)}
                                error={errors.brand_color}
                                disabled={!can.update}
                            />
                            {processing && data.logo !== null && (
                                <Progress
                                    className="md:col-span-2"
                                    label="Mengunggah logo"
                                    value={progress?.percentage ?? 0}
                                />
                            )}
                        </CardContent>
                        {can.update && (
                            <CardFooter className="justify-end">
                                <Button type="submit" loading={processing}>
                                    Simpan
                                </Button>
                            </CardFooter>
                        )}
                    </Card>
                </form>

                {can.requestSubsidiary ? (
                    <SubsidiaryRequestCard request={subsidiaryRequest} />
                ) : null}
            </div>
        </AdminLayout>
    );
}
