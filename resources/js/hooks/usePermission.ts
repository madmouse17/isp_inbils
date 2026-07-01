import { usePage } from '@inertiajs/react';
import { PageProps } from '@/types';

export function usePermission() {
    const { auth } = usePage<PageProps>().props;
    const permissions = auth.user?.permissions ?? [];

    return {
        can: (permission: string) => permissions.includes(permission),
        canAny: (perms: string[]) => perms.some((permission) => permissions.includes(permission)),
        permissions,
    };
}
