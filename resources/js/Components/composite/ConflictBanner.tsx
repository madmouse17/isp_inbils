import { router, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import type { PageProps } from '@/types';
import { Alert, AlertDescription, AlertTitle, Button } from '@/Components/ui';

/** The 409 an edit form got back (errors.conflict), with a reload, so a stale copy is never saved over a newer one. */
export function ConflictBanner() {
    const { props, url } = usePage<PageProps>();
    const conflict = props.errors.conflict;

    if (!conflict) return null;

    return (
        <Alert variant="warning" role="alert">
            <AlertTriangle className="h-4 w-4" aria-hidden="true" />
            <AlertTitle>{conflict}</AlertTitle>
            <AlertDescription>
                <p>Muat ulang halaman untuk melihat data terbaru, lalu ulangi perubahan Anda.</p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="mt-3"
                    // preserveState: false remounts the page, so the form restarts from the fresh row and its new expected_updated_at.
                    onClick={() =>
                        router.get(
                            url,
                            {},
                            { preserveScroll: true, preserveState: false, replace: true },
                        )
                    }
                >
                    Muat ulang
                </Button>
            </AlertDescription>
        </Alert>
    );
}
