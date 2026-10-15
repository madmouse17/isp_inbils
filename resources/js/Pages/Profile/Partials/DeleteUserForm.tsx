import { Button, buttonVariants } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Components/ui/alert-dialog';
import { useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import { useRef, useState } from 'react';

export default function DeleteUserForm({ className = '' }: { className?: string }) {
    const [confirmingUserDeletion, setConfirmingUserDeletion] = useState(false);
    const passwordInput = useRef<HTMLInputElement>(null);

    const {
        data,
        setData,
        delete: destroy,
        processing,
        reset,
        errors,
        clearErrors,
    } = useForm({
        password: '',
    });

    const confirmUserDeletion = () => {
        setConfirmingUserDeletion(true);
    };

    const deleteUser: FormEventHandler = (e) => {
        e.preventDefault();

        destroy(route('profile.destroy'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => passwordInput.current?.focus(),
            onFinish: () => reset(),
        });
    };

    const closeModal = () => {
        setConfirmingUserDeletion(false);

        clearErrors();
        reset();
    };

    return (
        <section className={`space-y-6 ${className}`}>
            <header>
                <h2 className="text-lg font-medium text-foreground">Hapus akun</h2>

                <p className="mt-1 text-sm text-muted-foreground">
                    Akun yang dihapus tidak bisa dipulihkan sendiri. Simpan dulu data yang masih
                    Anda perlukan.
                </p>
            </header>

            <Button type="button" variant="danger" onClick={confirmUserDeletion}>
                Hapus akun
            </Button>

            <AlertDialog open={confirmingUserDeletion} onOpenChange={setConfirmingUserDeletion}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Hapus akun Anda?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Akun dan akses Anda dihapus. Masukkan kata sandi untuk memastikan Anda
                            memang ingin menghapusnya.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <form onSubmit={deleteUser} className="space-y-4">
                        <Input
                            id="delete_password"
                            label="Kata sandi"
                            type="password"
                            name="password"
                            ref={passwordInput}
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            autoComplete="current-password"
                            autoFocus
                            error={errors.password}
                        />
                        <AlertDialogFooter>
                            <AlertDialogCancel
                                type="button"
                                onClick={closeModal}
                                disabled={processing}
                            >
                                Batal
                            </AlertDialogCancel>
                            <AlertDialogAction
                                type="submit"
                                disabled={processing}
                                className={buttonVariants({ variant: 'destructive' })}
                            >
                                {processing ? 'Menghapus…' : 'Hapus akun'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </form>
                </AlertDialogContent>
            </AlertDialog>
        </section>
    );
}
