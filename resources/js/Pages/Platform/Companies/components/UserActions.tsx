import { useState } from 'react';
import { Ellipsis, LogIn } from 'lucide-react';
import {
    Button,
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
    IconButton,
} from '@/Components/ui';
import { ConfirmActionDialog } from '@/Components/composite/platform/ConfirmActionDialog';
import { usePlatformAction } from '@/Components/composite/platform/usePlatformAction';
import type { PlatformCompanyUser, PlatformTenantProfile } from '@/types/platform';

type UserAction = 'impersonate' | 'two-factor' | 'invitation' | 'unlock';

const ROUTES = {
    impersonate: 'platform.impersonation.start',
    'two-factor': 'platform.companies.users.two-factor.reset',
    invitation: 'platform.companies.users.invitation.store',
    unlock: 'platform.companies.users.unlock',
} as const;

interface UserActionsProps {
    tenant: PlatformTenantProfile;
    user: PlatformCompanyUser;
}

/** What the operator can do for one person: support access, and the three account repairs of the support tools. */
export function UserActions({ tenant, user }: UserActionsProps) {
    const [action, setAction] = useState<UserAction | null>(null);
    const { processing, errors, post, clearErrors } = usePlatformAction();
    const canResend = user.invitation_pending && user.is_active;
    const hasMenu = user.two_factor_enabled || canResend;

    const close = () => {
        setAction(null);
        clearErrors();
    };
    const submit = (confirmation: Parameters<typeof post>[1]) => {
        if (!action) return;

        post(route(ROUTES[action], { company: tenant.id, user: user.id }), confirmation, close);
    };
    const dialog = { processing, errors, onConfirmed: submit, onClose: close };

    return (
        <div className="flex items-center gap-1">
            {user.can_impersonate ? (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    leftIcon={<LogIn className="size-4" aria-hidden="true" />}
                    aria-label={`Masuk sebagai ${user.name}`}
                    onClick={() => setAction('impersonate')}
                >
                    Masuk sebagai
                </Button>
            ) : null}
            {hasMenu ? (
                // Not modal: a modal menu and the confirm dialog would both lock the page.
                <DropdownMenu modal={false}>
                    <DropdownMenuTrigger asChild>
                        <IconButton label={`Aksi lain untuk ${user.name}`} size="sm">
                            <Ellipsis className="size-4" aria-hidden="true" />
                        </IconButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        {user.two_factor_enabled ? (
                            <>
                                <DropdownMenuItem onSelect={() => setAction('two-factor')}>
                                    Atur ulang 2FA
                                </DropdownMenuItem>
                                <DropdownMenuItem onSelect={() => setAction('unlock')}>
                                    Buka kunci masuk
                                </DropdownMenuItem>
                            </>
                        ) : null}
                        {canResend ? (
                            <DropdownMenuItem onSelect={() => setAction('invitation')}>
                                Kirim ulang undangan
                            </DropdownMenuItem>
                        ) : null}
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : null}

            {action === 'impersonate' ? (
                <ConfirmActionDialog
                    {...dialog}
                    title={`Masuk sebagai ${user.name}?`}
                    description="Anda membuka panel perusahaan sebagai pengguna ini. Akses dibatasi waktu, tercatat di audit, dan dimulai dengan mode baca."
                    confirmLabel="Mulai akses dukungan"
                    reason
                />
            ) : null}
            {action === 'two-factor' ? (
                <ConfirmActionDialog
                    {...dialog}
                    title={`Atur ulang 2FA ${user.name}?`}
                    description="Autentikasi dua langkah pengguna dimatikan dan semua sesi masuknya berakhir. Pengguna perlu mengaktifkannya lagi."
                    confirmLabel="Atur ulang 2FA"
                    tone="danger"
                />
            ) : null}
            {action === 'unlock' ? (
                <ConfirmActionDialog
                    {...dialog}
                    title={`Buka kunci masuk ${user.name}?`}
                    description="Hitungan kode 2FA yang salah dihapus. Pengguna dapat mencoba masuk lagi tanpa menunggu 15 menit."
                    confirmLabel="Buka kunci masuk"
                    requirePassword={false}
                />
            ) : null}
            {action === 'invitation' ? (
                <ConfirmActionDialog
                    {...dialog}
                    title={`Kirim ulang undangan ke ${user.email}?`}
                    description="Email undangan dikirim lagi kepada admin yang belum pernah masuk. Batasnya 6 kali per menit untuk setiap pengguna."
                    confirmLabel="Kirim ulang undangan"
                    requirePassword={false}
                />
            ) : null}
        </div>
    );
}
