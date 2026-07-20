import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

export function useCompany() {
    const { company } = usePage<PageProps>().props;

    return company;
}
