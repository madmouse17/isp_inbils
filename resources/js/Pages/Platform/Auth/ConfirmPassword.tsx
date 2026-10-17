import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Button, Input } from '@/Components/ui';
import PlatformAuthLayout from '@/Layouts/PlatformAuthLayout';

export default function ConfirmPassword() {
    const form = useForm({ password: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('platform.password.confirm.store'), {
            onFinish: () => form.reset('password'),
        });
    };

    return (
        <PlatformAuthLayout title="Konfirmasi kata sandi">
            <h1 className="text-xl font-semibold tracking-tight">Konfirmasi kata sandi</h1>
            <p className="mt-2 text-sm text-muted-foreground">
                Masukkan kata sandi akun operator sebelum melanjutkan tindakan sensitif.
            </p>

            <form onSubmit={submit} className="space-y-4 mt-5">
                <Input
                    id="platform-confirm-password"
                    label="Kata sandi"
                    type="password"
                    value={form.data.password}
                    autoComplete="current-password"
                    autoFocus
                    error={form.errors.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                    required
                />
                <Button type="submit" className="w-full" loading={form.processing}>
                    Konfirmasi
                </Button>
            </form>
        </PlatformAuthLayout>
    );
}
