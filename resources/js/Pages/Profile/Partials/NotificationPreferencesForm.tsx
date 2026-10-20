import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Switch } from '@/Components/ui/Switch';

export default function NotificationPreferencesForm({
    inventoryDigest,
}: {
    inventoryDigest: boolean;
}) {
    // The switch shows what the server holds; while a save runs it shows the value on its way there.
    const [pending, setPending] = useState<boolean | null>(null);

    const save = (next: boolean) => {
        setPending(next);
        router.patch(
            route('profile.notifications.update'),
            { inventory_digest: next },
            { preserveScroll: true, onFinish: () => setPending(null) },
        );
    };

    return (
        <section>
            <header>
                <h2 className="text-lg font-medium text-foreground">Notifikasi email</h2>

                <p className="mt-1 text-sm text-muted-foreground">
                    Satu email setiap pagi berisi permintaan dan retur yang menunggu Anda. Tanpa
                    ringkasan, pemberitahuan tetap muncul di lonceng.
                </p>
            </header>

            <div className="mt-6">
                <Switch
                    label="Ringkasan harian permintaan barang dan retur"
                    checked={pending ?? inventoryDigest}
                    onCheckedChange={save}
                    disabled={pending !== null}
                />
            </div>
        </section>
    );
}
