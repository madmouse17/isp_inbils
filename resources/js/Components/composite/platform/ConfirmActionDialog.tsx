import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import {
    Alert,
    AlertDescription,
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    Button,
    Input,
    Textarea,
} from '@/Components/ui';
import { ConfirmDescription } from '@/Components/composite/ConfirmDialog';

export type PlatformConfirmation = {
    reason?: string;
    confirmation_code?: string;
};

interface ConfirmActionDialogProps {
    title: string;
    description: string;
    /** What the action does, one line each: shown under the description. */
    details?: readonly string[];
    confirmLabel: string;
    onClose: () => void;
    onConfirmed: (confirmation: PlatformConfirmation) => void;
    processing?: boolean;
    errors?: Record<string, string | undefined>;
    tone?: 'primary' | 'danger';
    requirePassword?: boolean;
    reason?: boolean;
    companyCode?: string;
}

export function ConfirmActionDialog({
    title,
    description,
    details,
    confirmLabel,
    onClose,
    onConfirmed,
    processing = false,
    errors = {},
    tone = 'primary',
    requirePassword = true,
    reason = false,
    companyCode,
}: ConfirmActionDialogProps) {
    const firstField = useRef<HTMLTextAreaElement | HTMLInputElement>(null);
    const [reasonValue, setReasonValue] = useState('');
    const [confirmationCode, setConfirmationCode] = useState('');
    const password = useForm({ password: '' });
    const busy = processing || password.processing;
    const ready =
        (!reason || reasonValue.trim().length >= 5) &&
        (!companyCode || confirmationCode === companyCode) &&
        (!requirePassword || password.data.password !== '');
    const generalError = Object.entries(errors).find(
        ([key, value]) => value && !['reason', 'confirmation_code'].includes(key),
    )?.[1];

    const perform = () => {
        onConfirmed({
            ...(reason ? { reason: reasonValue.trim() } : {}),
            ...(companyCode ? { confirmation_code: confirmationCode } : {}),
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!ready || busy) return;

        if (!requirePassword) {
            perform();
            return;
        }

        password.post(route('platform.password.confirm.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: perform,
            onFinish: () => password.reset('password'),
        });
    };

    return (
        <AlertDialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) onClose();
            }}
        >
            <AlertDialogContent
                className="max-h-[calc(100dvh-2rem)] overflow-y-auto"
                onOpenAutoFocus={(event) => {
                    if (!firstField.current) return;
                    event.preventDefault();
                    firstField.current.focus();
                }}
            >
                <form onSubmit={submit} className="space-y-5">
                    <AlertDialogHeader>
                        <AlertDialogTitle>{title}</AlertDialogTitle>
                        <ConfirmDescription description={description} details={details} />
                    </AlertDialogHeader>

                    {generalError ? (
                        <Alert variant="danger">
                            <AlertDescription>{generalError}</AlertDescription>
                        </Alert>
                    ) : null}

                    {reason ? (
                        <Textarea
                            ref={firstField as React.RefObject<HTMLTextAreaElement>}
                            id="platform-action-reason"
                            label="Alasan"
                            value={reasonValue}
                            minLength={5}
                            maxLength={200}
                            hint={`${reasonValue.trim().length}/200 karakter. Alasan dicatat di audit.`}
                            error={errors.reason}
                            onChange={(event) => setReasonValue(event.target.value)}
                            required
                        />
                    ) : null}

                    {companyCode ? (
                        <Input
                            ref={
                                reason
                                    ? undefined
                                    : (firstField as React.RefObject<HTMLInputElement>)
                            }
                            id="platform-action-code"
                            label={`Ketik ${companyCode} untuk melanjutkan`}
                            value={confirmationCode}
                            autoComplete="off"
                            autoCapitalize="none"
                            autoCorrect="off"
                            spellCheck={false}
                            hint="Huruf besar dan kecil harus sama."
                            error={errors.confirmation_code}
                            onChange={(event) => setConfirmationCode(event.target.value)}
                            required
                        />
                    ) : null}

                    {requirePassword ? (
                        <Input
                            ref={
                                reason || companyCode
                                    ? undefined
                                    : (firstField as React.RefObject<HTMLInputElement>)
                            }
                            id="platform-action-password"
                            label="Kata sandi operator"
                            type="password"
                            value={password.data.password}
                            autoComplete="current-password"
                            hint="Gunakan kata sandi akun operator, bukan akun perusahaan."
                            error={password.errors.password}
                            onChange={(event) => password.setData('password', event.target.value)}
                            required
                        />
                    ) : null}

                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={busy}>Batal</AlertDialogCancel>
                        <Button
                            type="submit"
                            variant={tone === 'danger' ? 'danger' : 'primary'}
                            loading={busy}
                            disabled={!ready}
                        >
                            {confirmLabel}
                        </Button>
                    </AlertDialogFooter>
                </form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
