import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Transition } from '@headlessui/react';
import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
    className = '',
}: {
    mustVerifyEmail: boolean;
    status?: string;
    className?: string;
}) {
    const user = usePage().props.auth.user!;

    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: user.name,
        email: user.email,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('profile.update'));
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-foreground">Data akun</h2>

                <p className="mt-1 text-sm text-muted-foreground">
                    Ubah nama dan email yang dipakai untuk masuk.
                </p>
            </header>

            <form onSubmit={submit} className="space-y-6 mt-6">
                <Input
                    id="name"
                    label="Nama"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    required
                    autoComplete="name"
                    error={errors.name}
                />

                <Input
                    id="email"
                    label="Email"
                    type="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    required
                    autoComplete="username"
                    error={errors.email}
                />

                {mustVerifyEmail && user.email_verified_at === null && (
                    <div className="space-y-2 text-sm text-foreground">
                        <p>
                            Email Anda belum diverifikasi.{' '}
                            <Link
                                href={route('verification.send')}
                                method="post"
                                as="button"
                                className="rounded-md text-primary-text underline underline-offset-4 focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-hidden"
                            >
                                Kirim ulang email verifikasi
                            </Link>
                        </p>

                        {status === 'verification-link-sent' && (
                            <p
                                role="status"
                                className="font-medium text-emerald-700 dark:text-emerald-400"
                            >
                                Tautan verifikasi baru sudah dikirim ke email Anda.
                            </p>
                        )}
                    </div>
                )}

                <div className="flex items-center gap-4">
                    <Button type="submit" loading={processing}>
                        Simpan
                    </Button>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p role="status" className="text-sm text-muted-foreground">
                            Tersimpan.
                        </p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
