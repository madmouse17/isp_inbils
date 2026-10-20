import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import { useToast as useToastContext } from '@/Components/ui';

// A page can mount this hook next to its layout (Approvals, Dispatch and My Work do). Both read one page props object,
// so whichever instance runs first announces a response and the other skips it: one toast per message.
const announced = new WeakSet<object>();

export function useToast() {
    const { flash, errors } = usePage<PageProps>().props;
    const { toast } = useToastContext();

    useEffect(() => {
        if (announced.has(flash)) return;
        announced.add(flash);

        if (flash.success) toast({ title: flash.success, variant: 'success' });
        if (flash.error) toast({ title: flash.error, variant: 'danger' });
        if (flash.warning) toast({ title: flash.warning, variant: 'warning' });
    }, [flash, toast]);

    useEffect(() => {
        // A flash.error already says why (a refused action, a stock shortage), so no generic line sits beside it. A
        // conflict alone is shown by ConflictBanner, so it adds none either.
        if (flash.error || announced.has(errors)) return;
        announced.add(errors);

        if (Object.keys(errors).some((key) => key !== 'conflict')) {
            toast({ title: 'Data gagal disimpan. Periksa input.', variant: 'danger' });
        }
    }, [errors, flash.error, toast]);
}
