import { useMemo, useState } from 'react';
import { Badge, Checkbox } from '@/Components/ui';
import { cn } from '@/lib/utils';
import {
    groupPermissions,
    setGroupPermissions,
    setPermission,
    type PermissionOption,
} from './permissionGroups';

export interface PermissionGroupSelectorProps {
    permissions: PermissionOption[];
    value: string[];
    onChange: (permissions: string[]) => void;
    error?: string;
}

export function PermissionGroupSelector({
    permissions,
    value,
    onChange,
    error,
}: PermissionGroupSelectorProps) {
    const grouped = useMemo(() => groupPermissions(permissions), [permissions]);
    const groups = Object.entries(grouped);
    const [activeGroup, setActiveGroup] = useState(permissions[0]?.group ?? '');
    const currentGroup = grouped[activeGroup] ? activeGroup : (groups[0]?.[0] ?? '');
    const currentPermissions = grouped[currentGroup] ?? [];

    return (
        <div className="space-y-2">
            <p className="text-sm text-muted-foreground">
                Select a category checkbox to select or clear all permissions in that category.
            </p>
            <div className="grid overflow-hidden rounded-lg border border-border md:grid-cols-[17rem_1fr]">
                <div className="max-h-[30rem] overflow-y-auto border-b border-border bg-muted/20 md:border-b-0 md:border-r">
                    {groups.map(([group, items]) => {
                        const selectedCount = items.filter((item) =>
                            value.includes(item.name),
                        ).length;
                        const allSelected = selectedCount === items.length;

                        return (
                            <div
                                key={group}
                                className={cn(
                                    'flex items-center gap-3 border-b border-border px-3 py-2 last:border-b-0',
                                    currentGroup === group && 'bg-muted',
                                )}
                            >
                                <Checkbox
                                    aria-label={`Select all ${formatGroup(group)} permissions`}
                                    checked={allSelected}
                                    indeterminate={selectedCount > 0 && !allSelected}
                                    onCheckedChange={(checked) =>
                                        onChange(
                                            setGroupPermissions(value, items, checked === true),
                                        )
                                    }
                                />
                                <button
                                    type="button"
                                    className="flex min-w-0 flex-1 items-center justify-between gap-2 rounded-sm text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    onClick={() => setActiveGroup(group)}
                                >
                                    <span className="truncate text-sm font-medium">
                                        {formatGroup(group)}
                                    </span>
                                    <Badge variant={allSelected ? 'success' : 'secondary'}>
                                        {selectedCount}/{items.length}
                                    </Badge>
                                </button>
                            </div>
                        );
                    })}
                </div>

                <div className="min-h-72 p-4">
                    <div className="mb-4 flex items-center justify-between gap-3">
                        <div>
                            <h3 className="font-semibold">{formatGroup(currentGroup)}</h3>
                            <p className="text-xs text-muted-foreground">
                                {currentPermissions.length} permissions
                            </p>
                        </div>
                    </div>
                    <div className="grid max-h-[24rem] gap-3 overflow-y-auto pr-2 sm:grid-cols-2 xl:grid-cols-3">
                        {currentPermissions.map((permission) => (
                            <Checkbox
                                key={permission.id}
                                label={formatPermission(permission.name, currentGroup)}
                                title={permission.name}
                                checked={value.includes(permission.name)}
                                onCheckedChange={(checked) =>
                                    onChange(
                                        setPermission(value, permission.name, checked === true),
                                    )
                                }
                            />
                        ))}
                    </div>
                </div>
            </div>
            {error ? <p className="text-sm text-destructive">{error}</p> : null}
        </div>
    );
}

function formatGroup(group: string): string {
    if (group.length <= 4) {
        return group.toUpperCase();
    }

    return group.charAt(0).toUpperCase() + group.slice(1).replaceAll('_', ' ');
}

function formatPermission(permission: string, group: string): string {
    return permission
        .replace(`${group}.`, '')
        .split(/[._-]/)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}
