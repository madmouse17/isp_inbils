import { Lock } from 'lucide-react';
import { Badge, EnumBadge } from '@/Components/ui';
import { formatDateTime } from '@/lib/format';
import { ACTIVE_STATE, PLATFORM_INVITATION_STATE, resolveStatus } from '@/lib/statuses';
import type { PlatformCompanyUser, PlatformLoginLock } from '@/types/platform';

const LOCK_REASON: Record<PlatformLoginLock['reason'], string> = {
    password: 'Terlalu banyak kata sandi salah',
    two_factor: 'Terlalu banyak kode 2FA salah',
};

/** Whether the person is active, and for how long they cannot sign in when they cannot. Minutes round up, so a lock never reads shorter than it is. */
export function AccountStatusCell({ user }: { user: PlatformCompanyUser }) {
    const lock = user.login_lock;

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <EnumBadge map={ACTIVE_STATE} value={user.is_active} />
            {lock ? (
                <Badge variant="warning" title={LOCK_REASON[lock.reason]}>
                    <Lock className="size-3" aria-hidden="true" />
                    {lock.seconds < 60
                        ? 'Terkunci < 1 mnt'
                        : `Terkunci ${Math.ceil(lock.seconds / 60)} mnt`}
                </Badge>
            ) : null}
        </div>
    );
}

/** The last sign-in; for someone who never signed in, where their invitation stands. */
export function LastLoginCell({ user }: { user: PlatformCompanyUser }) {
    if (user.last_login_at) return <>{formatDateTime(user.last_login_at)}</>;

    const status = user.invitation
        ? resolveStatus(PLATFORM_INVITATION_STATE, user.invitation.state)
        : null;

    if (!user.invitation || !status)
        return <span className="text-muted-foreground">Belum pernah</span>;

    return (
        <Badge variant={status.tone}>
            {user.invitation.state === 'expired'
                ? status.label
                : `${status.label} ${formatDateTime(user.invitation.at)}`}
        </Badge>
    );
}
