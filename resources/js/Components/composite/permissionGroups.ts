export interface PermissionOption {
    id: number;
    name: string;
    group: string;
}

// ponytail: mirrors AdminLayout menu order; move both to shared navigation metadata when the sidebar becomes data-driven
const permissionGroupOrder = [
    'dashboard',
    'organization',
    'company',
    'users',
    'roles',
    'customer',
    'location',
    'employee',
    'vehicle',
    'document',
    'system',
    'service',
    'inventory',
    'network_asset',
    'spk',
    'billing',
    'ticket',
    'evaluation',
    'report',
];

export function groupPermissions(permissions: PermissionOption[]) {
    const grouped = permissions.reduce<Record<string, PermissionOption[]>>((groups, permission) => {
        groups[permission.group] = [...(groups[permission.group] ?? []), permission];

        return groups;
    }, {});

    return Object.fromEntries(
        Object.entries(grouped).sort(([left], [right]) => {
            const leftIndex = permissionGroupOrder.indexOf(left);
            const rightIndex = permissionGroupOrder.indexOf(right);

            if (leftIndex === -1 && rightIndex === -1) {
                return left.localeCompare(right);
            }

            if (leftIndex === -1) {
                return 1;
            }

            if (rightIndex === -1) {
                return -1;
            }

            return leftIndex - rightIndex;
        }),
    );
}

export function setGroupPermissions(
    selected: string[],
    permissions: PermissionOption[],
    checked: boolean,
): string[] {
    const next = new Set(selected);

    for (const permission of permissions) {
        if (checked) {
            next.add(permission.name);
        } else {
            next.delete(permission.name);
        }
    }

    return [...next];
}

export function setPermission(selected: string[], permission: string, checked: boolean): string[] {
    const next = new Set(selected);

    if (checked) {
        next.add(permission);
    } else {
        next.delete(permission);
    }

    return [...next];
}
