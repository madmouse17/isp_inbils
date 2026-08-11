import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

type PermissionChecker = ((permission: string) => boolean) & {
    can: (permission: string) => boolean;
    canAny: (perms: string[]) => boolean;
    permissions: string[];
};

export function usePermission(): PermissionChecker {
    const { auth } = usePage<PageProps>().props;
    const permissions = auth.user?.permissions ?? [];
    const check = (permission: string) => permissions.includes(permission);

    return Object.assign(check, {
        can: check,
        canAny: (perms: string[]) => perms.some((permission) => permissions.includes(permission)),
        permissions,
    });
}

export const useCan = usePermission;
