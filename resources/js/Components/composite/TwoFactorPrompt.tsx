import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { Alert, AlertDescription, Button } from '@/Components/ui';
import type { PageProps } from '@/types';

const SNOOZE_MS = 7 * 24 * 60 * 60 * 1000;

// Per user, not per browser: on a shared office PC one admin's "Nanti saja" must not hide the prompt for the next.
const snoozeKey = (userId: string) => `inbils.2faPromptSnoozedUntil.${userId}`;

function isSnoozed(userId: string): boolean {
    try {
        return Number(window.localStorage.getItem(snoozeKey(userId))) > Date.now();
    } catch {
        // Storage can be blocked (browser policy): the prompt then shows on every page, which is still right.
        return false;
    }
}

function snooze(userId: string): void {
    try {
        window.localStorage.setItem(snoozeKey(userId), String(Date.now() + SNOOZE_MS));
    } catch {
        // Blocked storage: the prompt comes back on the next page.
    }
}

/**
 * Asks an admin without two-factor authentication to turn it on, until they postpone it for a week. The server
 * decides who is asked (`twoFactorPrompt`): never a person the company already locks, never support staff.
 */
export function TwoFactorPrompt() {
    const { props, url } = usePage<PageProps>();
    const userId = props.auth.user?.id;
    const [postponed, setPostponed] = useState(() => (userId ? isSnoozed(userId) : false));

    // The Profile page holds the 2FA form: asking there would only repeat the page.
    if (!props.twoFactorPrompt || !userId || postponed || url.split('?')[0] === '/profile') {
        return null;
    }

    const postpone = () => {
        snooze(userId);
        setPostponed(true);
    };

    return (
        <Alert variant="info" role="region" aria-label="Keamanan akun">
            <ShieldAlert className="h-4 w-4" aria-hidden="true" />
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <AlertDescription>
                    Lindungi akun admin dengan 2FA. Satu kata sandi yang bocor cukup untuk membuka
                    data semua pelanggan.
                </AlertDescription>
                <div className="flex shrink-0 flex-wrap gap-2">
                    <Button asChild size="sm">
                        <Link href={route('profile.edit')}>Aktifkan 2FA</Link>
                    </Button>
                    <Button type="button" size="sm" variant="outline" onClick={postpone}>
                        Nanti saja
                    </Button>
                </div>
            </div>
        </Alert>
    );
}
