import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.store'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Atur ulang kata sandi" />

            <h1 className="mb-4 text-xl font-semibold tracking-tight text-foreground">
                Atur ulang kata sandi
            </h1>

            <form onSubmit={submit} className="space-y-4">
                <Input
                    id="email"
                    label="Email"
                    type="email"
                    name="email"
                    value={data.email}
                    autoComplete="username"
                    error={errors.email}
                    onChange={(e) => setData('email', e.target.value)}
                />

                <Input
                    id="password"
                    label="Kata sandi baru"
                    type="password"
                    name="password"
                    value={data.password}
                    autoComplete="new-password"
                    autoFocus
                    error={errors.password}
                    onChange={(e) => setData('password', e.target.value)}
                />

                <Input
                    id="password_confirmation"
                    label="Ulangi kata sandi baru"
                    type="password"
                    name="password_confirmation"
                    value={data.password_confirmation}
                    autoComplete="new-password"
                    error={errors.password_confirmation}
                    onChange={(e) => setData('password_confirmation', e.target.value)}
                />

                <Button type="submit" className="w-full" loading={processing}>
                    Simpan kata sandi
                </Button>
            </form>
        </GuestLayout>
    );
}
