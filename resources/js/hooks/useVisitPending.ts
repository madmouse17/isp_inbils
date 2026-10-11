import { useMemo, useState } from 'react';

/**
 * Pending flag for an ad-hoc Inertia visit (search submit, pagination, row action).
 * Spread `callbacks` into the visit options; `pending` follows the visit from start to finish.
 */
export function useVisitPending() {
    const [pending, setPending] = useState(false);
    const callbacks = useMemo(
        () => ({
            onStart: () => setPending(true),
            onFinish: () => setPending(false),
        }),
        [],
    );

    return { pending, callbacks };
}
