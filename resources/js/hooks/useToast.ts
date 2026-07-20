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
        if (Object.keys(errors).length > 0) {
            toast({ title: 'Data gagal disimpan. Periksa input.', variant: 'danger' });
        }
    }, [errors, toast]);
}
