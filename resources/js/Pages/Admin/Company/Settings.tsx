import { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardFooter, CardHeader, CardTitle, Input, Switch } from '@/Components/ui';

type SettingValue = string | number | boolean;

interface SettingsProps {
    settings: Record<string, SettingValue>;
    defaults: Record<string, SettingValue | null>;
    can: { update: boolean };
}

export default function Settings({ settings, defaults, can }: SettingsProps) {
    const { data, setData, put, processing, errors } = useForm({ settings: { ...settings } });
    const keys = Object.keys(data.settings).sort();
    const settingErrors = errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('admin.company.settings.update'));
    };

    const setSetting = (key: string, value: SettingValue) => setData('settings', { ...data.settings, [key]: value });

    return (
        <AdminLayout title="Company Settings">
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">Company Settings</h2>
                        <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">Company-specific config. Defaults: {Object.keys(defaults).join(', ')}</p>
                    </div>
                    <Link href={route('admin.company.profile.edit')} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">
                        Profile
                    </Link>
                </div>

                <form onSubmit={submit}>
                    <Card>
                        <CardHeader>
                            <CardTitle>Settings JSON</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            {keys.map((key) => {
                                const value = data.settings[key];
                                if (typeof value === 'boolean') {
                                    return <Switch key={key} label={key} checked={value} disabled={!can.update} onCheckedChange={(checked) => setSetting(key, checked)} />;
                                }

                                return (
                                    <Input
                                        key={key}
                                        label={key}
                                        type={typeof value === 'number' ? 'number' : 'text'}
                                        value={String(value ?? '')}
                                        onChange={(e) => setSetting(key, typeof value === 'number' ? Number(e.target.value) : e.target.value)}
                                        error={settingErrors[`settings.${key}`]}
                                        disabled={!can.update}
                                    />
                                );
                            })}
                        </CardContent>
                        {can.update && (
                            <CardFooter className="justify-end">
                                <Button type="submit" loading={processing}>Save Settings</Button>
                            </CardFooter>
                        )}
                    </Card>
                </form>
            </div>
        </AdminLayout>
    );
}
