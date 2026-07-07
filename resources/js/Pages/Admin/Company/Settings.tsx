import { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardFooter, CardHeader, CardTitle, Input, Switch } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

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
                <PageHeader
                    title="Company Settings"
                    subtitle={`Company-specific config. Defaults: ${Object.keys(defaults).join(', ')}`}
                    actions={<Link href={route('admin.company.profile.edit')} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Profile</Link>}
                />

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
