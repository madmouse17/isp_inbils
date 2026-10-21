import { useId } from 'react';
import { Link } from '@inertiajs/react';
import { Badge, Checkbox } from '@/Components/ui';
import { useCan } from '@/hooks/useCan';

/** A role the user may give; `grantable` is false when it carries a permission he does not hold (G1). */
export interface RoleOption {
    name: string;
    grantable: boolean;
}

/**
 * The roles a user holds, split by whether this page offers them. Customer-account roles are not offered
 * (CustomerService owns them), so they are `outside`: the form never sends them and a save keeps them.
 */
export function splitHeldRoles(held: string[], options: RoleOption[]) {
    const offered = new Set(options.map((option) => option.name));

    return {
        inside: held.filter((name) => offered.has(name)),
        outside: held.filter((name) => !offered.has(name)),
    };
}

/** Why a box is disabled; every such box points at its own line with `aria-describedby`. */
const NOT_HELD = 'Peran ini memuat hak akses yang tidak Anda pegang';

interface RoleChecklistProps {
    options: RoleOption[];
    value: string[];
    onChange: (roles: string[]) => void;
    /** The server's refusal: the company would lose its last administrator, a role he does not hold himself, a conflict of duties. */
    error?: string;
    /** Roles the user holds that this list does not offer. */
    outside?: string[];
}

export function RoleChecklist({
    options,
    value,
    onChange,
    error,
    outside = [],
}: RoleChecklistProps) {
    const prefix = useId();
    const errorId = `${prefix}-error`;
    const can = useCan();

    return (
        <fieldset className="space-y-2" aria-describedby={error ? errorId : undefined}>
            <legend className="text-sm font-medium text-foreground">Peran</legend>
            {options.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Perusahaan ini belum punya peran yang bisa diberikan di sini.{' '}
                    {can('roles.manage') ? (
                        <Link
                            href={route('admin.roles.create')}
                            className="font-medium text-primary-text hover:underline"
                        >
                            Buat peran
                        </Link>
                    ) : (
                        'Minta admin perusahaan membuat peran.'
                    )}
                </p>
            ) : (
                <div className="grid gap-x-4 gap-y-2 sm:grid-cols-2">
                    {options.map((option, index) => {
                        const checked = value.includes(option.name);
                        // A role he already holds can still be taken away; one he may not give cannot be added.
                        const locked = !option.grantable && !checked;
                        const hintId = `${prefix}-hint-${index}`;

                        return (
                            <div key={option.name}>
                                <Checkbox
                                    id={`${prefix}-role-${index}`}
                                    label={option.name}
                                    checked={checked}
                                    disabled={locked}
                                    aria-describedby={locked ? hintId : undefined}
                                    onChange={(event) =>
                                        onChange(
                                            event.target.checked
                                                ? [...value, option.name]
                                                : value.filter((item) => item !== option.name),
                                        )
                                    }
                                />
                                {locked ? (
                                    <p id={hintId} className="pl-6 text-xs text-muted-foreground">
                                        {NOT_HELD}
                                    </p>
                                ) : null}
                            </div>
                        );
                    })}
                </div>
            )}
            {outside.length > 0 ? (
                <div className="space-y-1">
                    <div className="flex flex-wrap gap-1">
                        {outside.map((name) => (
                            <Badge key={name} variant="neutral">
                                {name}
                            </Badge>
                        ))}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Peran pelanggan dikelola dari data pelanggan.
                    </p>
                </div>
            ) : null}
            {error ? (
                <p id={errorId} role="alert" className="text-sm text-destructive-text">
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
