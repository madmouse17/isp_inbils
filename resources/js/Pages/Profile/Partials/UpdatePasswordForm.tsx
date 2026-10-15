import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Transition } from '@headlessui/react';
import { useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import { useRef } from 'react';

export default function UpdatePasswordForm({ className = '' }: { className?: string }) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const { data, setData, errors, put, reset, processing, recentlySuccessful } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const updatePassword: FormEventHandler = (e) => {
        e.preventDefault();

        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current?.focus();
                }

                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current?.focus();
                }
            },
        });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-foreground">Ganti kata sandi</h2>

                <p className="mt-1 text-sm text-muted-foreground">
                    Pakai kata sandi yang panjang dan acak agar akun tetap aman.
                </p>
            </header>

            <form onSubmit={updatePassword} className="space-y-6 mt-6">
                <Input
                    id="current_password"
                    ref={currentPasswordInput}
                    label="Kata sandi saat ini"
                    type="password"
                    value={data.current_password}
                    onChange={(e) => setData('current_password', e.target.value)}
                    autoComplete="current-password"
                    error={errors.current_password}
                />

                <Input
                    id="password"
                    ref={passwordInput}
                    label="Kata sandi baru"
                    type="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    autoComplete="new-password"
                    error={errors.password}
                />

                <Input
                    id="password_confirmation"
                    label="Ulangi kata sandi baru"
                    type="password"
                    value={data.password_confirmation}
                    onChange={(e) => setData('password_confirmation', e.target.value)}
                    autoComplete="new-password"
                    error={errors.password_confirmation}
                />

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
