import { navGroups } from '@/lib/navigation';

export interface PermissionOption {
    id: number;
    name: string;
    group: string;
}

const OTHER_SECTION = 'Other';

/**
 * Permission groups whose page is gated by another group's permission: they sit right after that group.
 * `user.two_factor.reset` is used on the Users pages, `document.export` on the Documents page (`system.setting`).
 */
const companionGroups: Record<string, readonly string[]> = {
    users: ['user'],
    system: ['document'],
};

/**
 * Permission groups in sidebar order, each tied to the sidebar group that first reaches it.
 * An item contributes the prefix of its first `anyOf` permission, then that prefix's companion groups;
 * flag-only items contribute none.
 * A standalone sidebar entry has no title, so its section is `null`: the editor shows no heading.
 */
const sidebarSections = new Map<string, string | null>();

for (const navGroup of navGroups) {
    for (const item of navGroup.items) {
        const prefix = item.anyOf?.[0].split('.')[0];

        if (!prefix || sidebarSections.has(prefix)) {
            continue;
        }

        for (const group of [prefix, ...(companionGroups[prefix] ?? [])]) {
            sidebarSections.set(group, navGroup.title ?? null);
        }
    }
}

const permissionGroupOrder = [...sidebarSections.keys()];

/** Heading shown above a permission group in the editor: its sidebar group title, `null` for none. */
export function permissionGroupSection(group: string): string | null {
    return sidebarSections.has(group) ? (sidebarSections.get(group) ?? null) : OTHER_SECTION;
}

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
