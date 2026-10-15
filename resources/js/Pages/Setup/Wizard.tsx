import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import SetupLayout from '@/Layouts/SetupLayout';
import {
    Button,
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
    FileUpload,
    Input,
    NativeSelect,
    Progress,
    SearchSelect,
    Textarea,
} from '@/Components/ui';

interface SetupWizardProps {
    user: {
        name: string;
        email: string;
    };
    defaults: {
        currency: string;
        timezone: string;
        date_format: string;
        datetime_format: string;
        timezones: string[];
    };
}

interface SetupFormData {
    name: string;
    code: string;
    logo: File | null;
    address: string;
    phone: string;
    email: string;
    website: string;
    currency: string;
    timezone: string;
    date_format: string;
    datetime_format: string;
    admin_name: string;
}

const steps = ['Data perusahaan', 'Pengaturan sistem', 'Admin pertama', 'Konfirmasi'];

export default function Wizard({ user, defaults }: SetupWizardProps) {
    const [step, setStep] = useState(0);
    const [localError, setLocalError] = useState<string | null>(null);
    const initialData: SetupFormData = {
        name: '',
        code: '',
        logo: null,
        address: '',
        phone: '',
        email: '',
        website: '',
        currency: defaults.currency,
        timezone: defaults.timezone,
        date_format: defaults.date_format,
        datetime_format: defaults.datetime_format,
        admin_name: user.name,
    };
    const { data, setData, post, processing, progress, errors } = useForm(initialData);
    const timezoneOptions = useMemo(
        () => defaults.timezones.map((timezone) => ({ value: timezone, label: timezone })),
        [defaults.timezones],
    );

    const next = () => {
        const message = validateStep(step, data);

        if (message) {
            setLocalError(message);
            return;
        }

        setLocalError(null);
        setStep((current) => Math.min(current + 1, steps.length - 1));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        // Enter inside a field must not skip the remaining steps.
        if (step < steps.length - 1) {
            next();
            return;
        }

        complete();
    };

    const complete = () => {
        post('/setup');
    };

    return (
        <SetupLayout title="Pengaturan awal">
            <Card>
                <CardHeader>
                    <CardTitle>Buat perusahaan Anda</CardTitle>
                    <CardDescription>
                        Isi sekali untuk membuat profil perusahaan dan admin pertama. Semuanya bisa
                        diubah lagi di Pengaturan Perusahaan.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <ol className="grid gap-2 sm:grid-cols-4">
                        {steps.map((label, index) => (
                            <li
                                key={label}
                                className={
                                    index === step
                                        ? 'rounded-lg bg-brand-50 px-3 py-2 text-sm font-medium text-brand-700 dark:bg-brand-900/30 dark:text-brand-200'
                                        : 'rounded-lg bg-surface-100 px-3 py-2 text-sm text-surface-500 dark:bg-surface-800 dark:text-surface-400'
                                }
                            >
                                {index + 1}. {label}
                            </li>
                        ))}
                    </ol>

                    {localError && (
                        <p className="mt-4 rounded-lg bg-danger/10 px-3 py-2 text-sm text-destructive-text">
                            {localError}
                        </p>
                    )}

                    {step === steps.length - 1 && Object.keys(errors).length > 0 && (
                        <p className="mt-4 rounded-lg bg-danger/10 px-3 py-2 text-sm text-destructive-text">
                            Data belum bisa disimpan: {Object.values(errors).join(' ')} Kembali ke
                            langkah yang bersangkutan untuk memperbaikinya.
                        </p>
                    )}

                    <form onSubmit={submit} className="space-y-5 mt-6">
                        {step === 0 && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Input
                                    label="Nama perusahaan"
                                    required
                                    value={data.name}
                                    onChange={(event) => setData('name', event.target.value)}
                                    error={errors.name}
                                />
                                <Input
                                    label="Kode perusahaan"
                                    required
                                    value={data.code}
                                    onChange={(event) =>
                                        setData('code', event.target.value.toUpperCase())
                                    }
                                    error={errors.code}
                                />
                                <Input
                                    label="Email"
                                    type="email"
                                    value={data.email}
                                    onChange={(event) => setData('email', event.target.value)}
                                    error={errors.email}
                                />
                                <Input
                                    label="Telepon"
                                    value={data.phone}
                                    onChange={(event) => setData('phone', event.target.value)}
                                    error={errors.phone}
                                />
                                <Input
                                    label="Website"
                                    type="url"
                                    value={data.website}
                                    onChange={(event) => setData('website', event.target.value)}
                                    error={errors.website}
                                />
                                <FileUpload
                                    label="Logo"
                                    value={data.logo}
                                    onChange={(file) => setData('logo', file)}
                                    acceptedFileTypes={['image/jpeg', 'image/png', 'image/svg+xml']}
                                    error={errors.logo}
                                />
                                <div className="sm:col-span-2">
                                    <Textarea
                                        label="Alamat"
                                        value={data.address}
                                        onChange={(event) => setData('address', event.target.value)}
                                        error={errors.address}
                                    />
                                </div>
                            </div>
                        )}

                        {step === 1 && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <NativeSelect
                                    label="Mata uang"
                                    required
                                    value={data.currency}
                                    onChange={(event) => setData('currency', event.target.value)}
                                    error={errors.currency}
                                    options={[
                                        { value: 'IDR', label: 'IDR' },
                                        { value: 'USD', label: 'USD' },
                                        { value: 'SGD', label: 'SGD' },
                                        { value: 'EUR', label: 'EUR' },
                                    ]}
                                />
                                <SearchSelect
                                    id="timezone"
                                    label="Zona waktu"
                                    required
                                    value={data.timezone}
                                    onChange={(timezone) => setData('timezone', timezone)}
                                    error={errors.timezone}
                                    options={timezoneOptions}
                                    placeholder="Cari zona waktu"
                                    emptyText="Zona waktu tidak ditemukan."
                                />
                                <Input
                                    label="Format tanggal"
                                    hint="Mis. d M Y atau d/m/Y."
                                    required
                                    value={data.date_format}
                                    onChange={(event) => setData('date_format', event.target.value)}
                                    error={errors.date_format}
                                />
                                <Input
                                    label="Format tanggal dan jam"
                                    hint="Mis. d M Y H:i."
                                    required
                                    value={data.datetime_format}
                                    onChange={(event) =>
                                        setData('datetime_format', event.target.value)
                                    }
                                    error={errors.datetime_format}
                                />
                            </div>
                        )}

                        {step === 2 && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Input
                                    label="Nama admin"
                                    required
                                    value={data.admin_name}
                                    onChange={(event) => setData('admin_name', event.target.value)}
                                    error={errors.admin_name}
                                />
                                <Input label="Email admin" value={user.email} disabled />
                            </div>
                        )}

                        {step === 3 && (
                            <div className="rounded-xl border border-surface-200 bg-surface-50 p-4 text-sm dark:border-surface-800 dark:bg-surface-900">
                                <dl className="grid gap-3 sm:grid-cols-2">
                                    <Summary label="Perusahaan" value={data.name} />
                                    <Summary label="Kode" value={data.code} />
                                    <Summary label="Mata uang" value={data.currency} />
                                    <Summary label="Zona waktu" value={data.timezone} />
                                    <Summary label="Admin" value={data.admin_name} />
                                    <Summary label="Email admin" value={user.email} />
                                </dl>
                            </div>
                        )}
                    </form>

                    {processing && data.logo !== null && (
                        <Progress
                            className="mt-4"
                            label="Mengunggah logo"
                            value={progress?.percentage ?? 0}
                        />
                    )}
                </CardContent>
                <CardFooter className="flex justify-between gap-3">
                    <Button
                        type="button"
                        variant="secondary"
                        disabled={step === 0 || processing}
                        onClick={() => setStep((current) => Math.max(current - 1, 0))}
                    >
                        Kembali
                    </Button>
                    {step < steps.length - 1 ? (
                        <Button type="button" onClick={next}>
                            Lanjut
                        </Button>
                    ) : (
                        <Button type="button" loading={processing} onClick={complete}>
                            Selesaikan
                        </Button>
                    )}
                </CardFooter>
            </Card>
        </SetupLayout>
    );
}

function Summary({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-surface-500 dark:text-surface-400">{label}</dt>
            <dd className="mt-1 font-medium text-surface-900 dark:text-surface-100">
                {value || '-'}
            </dd>
        </div>
    );
}

function validateStep(step: number, data: SetupFormData): string | null {
    if (step === 0 && (!data.name || !data.code)) {
        return 'Nama dan kode perusahaan wajib diisi.';
    }

    if (
        step === 1 &&
        (!data.currency || !data.timezone || !data.date_format || !data.datetime_format)
    ) {
        return 'Mata uang, zona waktu, dan kedua format wajib diisi.';
    }

    if (step === 2 && !data.admin_name) {
        return 'Nama admin wajib diisi.';
    }

    return null;
}
