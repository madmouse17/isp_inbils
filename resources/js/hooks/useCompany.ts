import { usePage } from '@inertiajs/react';
import { PageProps } from '@/types';

export function useCompany() {
    const { company } = usePage<PageProps>().props;

    return company;
}
