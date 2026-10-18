import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import { useToast as useToastContext } from '@/Components/ui';

export function useToast() {
    const { flash, errors } = usePage<PageProps>().props;
    const { toast } = useToastContext();

    useEffect(() => {
        if (flash.success) toast({ title: flash.success, variant: 'success' });
        if (flash.error) toast({ title: flash.error, variant: 'danger' });
        if (flash.warning) toast({ title: flash.warning, variant: 'warning' });
    }, [flash, toast]);

    useEffect(() => {
        // A conflict is already announced by the flash.error toast (and ConflictBanner), so it alone must not add a generic one.
        if (Object.keys(errors).some((key) => key !== 'conflict')) {
            toast({ title: 'Data gagal disimpan. Periksa input.', variant: 'danger' });
        }
    }, [errors, toast]);
}
