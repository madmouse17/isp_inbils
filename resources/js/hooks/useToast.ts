import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

export function useToast() {
    const { flash } = usePage<PageProps>().props;

    useEffect(() => {
        if (flash.success) console.warn('success:', flash.success);
        if (flash.error) console.error('error:', flash.error);
        if (flash.warning) console.warn('warning:', flash.warning);
    }, [flash]);
}
