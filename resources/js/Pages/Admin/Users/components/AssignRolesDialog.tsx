import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Button, Modal } from '@/Components/ui';
import { RoleChecklist, splitHeldRoles, type RoleOption } from './RoleChecklist';

interface AssignRolesDialogProps {
    user: { id: string; name: string; roles: string[] };
    roleOptions: RoleOption[];
    onClose: () => void;
}

/**
 * The roles one member holds in this company, also for a member homed elsewhere (his login fields are not edited
 * here). Mount it only while open, keyed by the user, so each opening starts from his roles with no stale refusal.
 */
export function AssignRolesDialog({ user, roleOptions, onClose }: AssignRolesDialogProps) {
    const { inside, outside } = splitHeldRoles(user.roles, roleOptions);
    const form = useForm({ roles: inside });
    // `roles` (the last administrator, a role he may not give) or `roles.N` (a name this company does not know).
    const rolesError = Object.entries(form.errors).find(([key]) => key.startsWith('roles'))?.[1];

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (form.processing) return;

        form.put(route('admin.users.roles.update', user.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal
            open
            title={`Atur peran ${user.name}`}
            onClose={() => {
                if (!form.processing) onClose();
            }}
        >
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-muted-foreground">
                    Peran ini berlaku hanya di perusahaan ini. Pengguna tanpa peran tidak bisa
                    membuka menu apa pun.
                </p>
                <div className="max-h-80 overflow-y-auto">
                    <RoleChecklist
                        options={roleOptions}
                        value={form.data.roles}
                        onChange={(roles) => form.setData('roles', roles)}
                        error={rolesError}
                        outside={outside}
                    />
                </div>
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={form.processing}
                        onClick={onClose}
                    >
                        Batal
                    </Button>
                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={roleOptions.length === 0}
                    >
                        Simpan peran
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
