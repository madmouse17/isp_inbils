import { FormEvent, useState } from 'react';
import { useForm } from '@inertiajs/react';
import SetupLayout from '@/Layouts/SetupLayout';
import { Button, Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle, Input, Select, Textarea } from '@/Components/ui';

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

const steps = ['Company info', 'System config', 'Initial admin', 'Confirmation'];

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
    const { data, setData, post, processing, errors } = useForm(initialData);

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
        complete();
    };

    const complete = () => {
        post('/setup');
    };

    return (
        <SetupLayout title="Setup Wizard">
            <Card>
                <CardHeader>
                    <CardTitle>Setup Wizard</CardTitle>
                    <CardDescription>Complete once to create company profile and first admin.</CardDescription>
                </CardHeader>
                <CardContent>
                    <ol className="grid gap-2 sm:grid-cols-4">
                        {steps.map((label, index) => (
                            <li key={label} className={index === step ? 'rounded-lg bg-brand-50 px-3 py-2 text-sm font-medium text-brand-700 dark:bg-brand-900/30 dark:text-brand-200' : 'rounded-lg bg-surface-100 px-3 py-2 text-sm text-surface-500 dark:bg-surface-800 dark:text-surface-400'}>
                                {index + 1}. {label}
                            </li>
                        ))}
                    </ol>

                    {localError && <p className="mt-4 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{localError}</p>}

                    <form onSubmit={submit} className="mt-6 space-y-5">
                        {step === 0 && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Input label="Company name" required value={data.name} onChange={(event) => setData('name', event.target.value)} error={errors.name} />
                                <Input label="Company code" required value={data.code} onChange={(event) => setData('code', event.target.value.toUpperCase())} error={errors.code} />
                                <Input label="Email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} error={errors.email} />
                                <Input label="Phone" value={data.phone} onChange={(event) => setData('phone', event.target.value)} error={errors.phone} />
                                <Input label="Website" type="url" value={data.website} onChange={(event) => setData('website', event.target.value)} error={errors.website} />
                                <Input label="Logo" type="file" accept="image/jpeg,image/png,image/svg+xml" onChange={(event) => setData('logo', event.target.files?.[0] ?? null)} error={errors.logo} />
                                <div className="sm:col-span-2">
                                    <Textarea label="Address" value={data.address} onChange={(event) => setData('address', event.target.value)} error={errors.address} />
                                </div>
                            </div>
                        )}

                        {step === 1 && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Select label="Currency" required value={data.currency} onChange={(event) => setData('currency', event.target.value)} error={errors.currency} options={[
                                    { value: 'IDR', label: 'IDR' },
                                    { value: 'USD', label: 'USD' },
                                    { value: 'SGD', label: 'SGD' },
                                    { value: 'EUR', label: 'EUR' },
                                ]} />
                                <Select label="Timezone" required value={data.timezone} onChange={(event) => setData('timezone', event.target.value)} error={errors.timezone} options={defaults.timezones.map((timezone) => ({ value: timezone, label: timezone }))} />
                                <Input label="Date format" required value={data.date_format} onChange={(event) => setData('date_format', event.target.value)} error={errors.date_format} />
                                <Input label="Datetime format" required value={data.datetime_format} onChange={(event) => setData('datetime_format', event.target.value)} error={errors.datetime_format} />
                            </div>
                        )}

                        {step === 2 && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Input label="Admin name" required value={data.admin_name} onChange={(event) => setData('admin_name', event.target.value)} error={errors.admin_name} />
                                <Input label="Admin email" value={user.email} disabled />
                            </div>
                        )}

                        {step === 3 && (
                            <div className="rounded-xl border border-surface-200 bg-surface-50 p-4 text-sm dark:border-surface-800 dark:bg-surface-900">
                                <dl className="grid gap-3 sm:grid-cols-2">
                                    <Summary label="Company" value={data.name} />
                                    <Summary label="Code" value={data.code} />
                                    <Summary label="Currency" value={data.currency} />
                                    <Summary label="Timezone" value={data.timezone} />
                                    <Summary label="Admin" value={data.admin_name} />
                                    <Summary label="Admin email" value={user.email} />
                                </dl>
                            </div>
                        )}
                    </form>
                </CardContent>
                <CardFooter className="flex justify-between gap-3">
                    <Button type="button" variant="secondary" disabled={step === 0 || processing} onClick={() => setStep((current) => Math.max(current - 1, 0))}>
                        Back
                    </Button>
                    {step < steps.length - 1 ? (
                        <Button type="button" onClick={next}>Next</Button>
                    ) : (
                        <Button type="button" loading={processing} onClick={complete}>Complete setup</Button>
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
            <dd className="mt-1 font-medium text-surface-900 dark:text-surface-100">{value || '-'}</dd>
        </div>
    );
}

function validateStep(step: number, data: SetupFormData): string | null {
    if (step === 0 && (!data.name || !data.code)) {
        return 'Company name and code are required.';
    }

    if (step === 1 && (!data.currency || !data.timezone || !data.date_format || !data.datetime_format)) {
        return 'System config fields are required.';
    }

    if (step === 2 && !data.admin_name) {
        return 'Admin name is required.';
    }

    return null;
}
