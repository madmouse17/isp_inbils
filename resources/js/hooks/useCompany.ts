import { usePage } from '@inertiajs/react';
import type { Company, PageProps } from '@/types';

type CompanyProp = Company | { data: Company } | null;

export function useCompany(): Company | null {
    const { company } = usePage<PageProps & { company?: CompanyProp }>().props;

    if (company && 'data' in company) {
        return company.data;
    }

    return company ?? null;
}
