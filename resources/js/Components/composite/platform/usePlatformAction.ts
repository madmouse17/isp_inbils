import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { useToast } from '@/Components/ui';
import { throttleMessage } from '@/lib/platform';
import type { PlatformConfirmation } from './ConfirmActionDialog';

/**
 * One confirmed POST of the operator panel: a guard against a double click, the errors of the visit for
 * the dialog to show, and the plain 429 of a throttled action, which Inertia would show as raw HTML in a frame.
 */
export function usePlatformAction() {
    const { toast } = useToast();
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string | undefined>>({});
    const inFlight = useRef(false);

    const post = (url: string, data: PlatformConfirmation, onSuccess: () => void) => {
        if (inFlight.current) return;

        inFlight.current = true;
        setProcessing(true);
        setErrors({});
        const stopListening = router.on('invalid', (event) => {
            if (event.detail.response.status !== 429) return;

            event.preventDefault();
            toast({
                title: throttleMessage(event.detail.response.headers['retry-after']),
                variant: 'danger',
            });
        });

        router.post(url, data, {
            preserveScroll: true,
            preserveState: true,
            onError: (visitErrors) => setErrors(visitErrors as Record<string, string | undefined>),
            onSuccess,
            onFinish: () => {
                stopListening();
                inFlight.current = false;
                setProcessing(false);
            },
        });
    };

    return { processing, errors, post, clearErrors: () => setErrors({}) };
}
