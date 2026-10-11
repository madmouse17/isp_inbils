import InputError from '@/Components/InputError';
import { Button } from '@/Components/ui/Button';
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
                <h2 className="text-lg font-medium text-gray-900 dark:text-gray-100">
                    Delete Account
                </h2>

                <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Once your account is deleted, all of its resources and data will be permanently
                    deleted. Before deleting your account, please download any data or information
                    that you wish to retain.
                </p>
            </header>

            <Button type="button" variant="danger" onClick={confirmUserDeletion}>
                Delete Account
            </Button>

            <AlertDialog open={confirmingUserDeletion} onOpenChange={setConfirmingUserDeletion}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Are you sure you want to delete your account?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Once your account is deleted, all of its resources and data will be
                            permanently deleted. Please enter your password to confirm you would
                            like to permanently delete your account.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <form onSubmit={deleteUser} className="space-y-4">
                        <div>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                ref={passwordInput}
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                className="mt-1 block w-full"
                                autoFocus
                                placeholder="Password"
                            />
                            <InputError message={errors.password} className="mt-2" />
                        </div>
                        <AlertDialogFooter>
                            <AlertDialogCancel
                                type="button"
                                onClick={closeModal}
                                disabled={processing}
                            >
                                Cancel
                            </AlertDialogCancel>
                            <AlertDialogAction
                                type="submit"
                                disabled={processing}
                                className="bg-destructive text-destructive-foreground shadow-sm hover:bg-destructive/90"
                            >
                                {processing ? 'Deleting…' : 'Delete Account'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </form>
                </AlertDialogContent>
            </AlertDialog>
        </section>
    );
}
