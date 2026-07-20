import { useForm } from '@inertiajs/react';
import type { FormDataType, UseFormSubmitOptions } from '@inertiajs/core';
import { useToast } from '@/Components/ui';

type SubmitOptions = UseFormSubmitOptions & {
    successMessage?: string;
    errorMessage?: string;
};

export function useInertiaForm<TForm extends FormDataType<TForm>>(initialValues: TForm) {
    const form = useForm<TForm>(initialValues);
    const { toast } = useToast();

    const withToast = (options: SubmitOptions = {}): UseFormSubmitOptions => ({
        ...options,
        onSuccess: (page) => {
            if (options.successMessage)
                toast({ title: options.successMessage, variant: 'success' });
            options.onSuccess?.(page);
        },
        onError: (errors) => {
            if (options.errorMessage) toast({ title: options.errorMessage, variant: 'danger' });
            options.onError?.(errors);
        },
    });

    return {
        ...form,
        get: (url: string, options?: SubmitOptions) => form.get(url, withToast(options)),
        post: (url: string, options?: SubmitOptions) => form.post(url, withToast(options)),
        put: (url: string, options?: SubmitOptions) => form.put(url, withToast(options)),
        patch: (url: string, options?: SubmitOptions) => form.patch(url, withToast(options)),
        delete: (url: string, options?: SubmitOptions) => form.delete(url, withToast(options)),
    };
}
