import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.confirm'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Konfirmasi kata sandi" />

            <h1 className="text-xl font-semibold tracking-tight text-foreground">
                Konfirmasi kata sandi
            </h1>

            <p className="mt-2 mb-4 text-sm text-muted-foreground">
                Bagian ini menyangkut keamanan akun. Masukkan kata sandi Anda untuk melanjutkan.
            </p>

            <form onSubmit={submit} className="space-y-4">
                <Input
                    id="password"
                    label="Kata sandi"
                    type="password"
                    name="password"
                    value={data.password}
                    autoComplete="current-password"
                    autoFocus
                    error={errors.password}
                    onChange={(e) => setData('password', e.target.value)}
                />

                <Button type="submit" className="w-full" loading={processing}>
                    Konfirmasi
                </Button>
            </form>
        </GuestLayout>
    );
}
