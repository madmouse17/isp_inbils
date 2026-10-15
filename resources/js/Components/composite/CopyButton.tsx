import { useEffect, useState } from 'react';
import { Button, type ButtonProps } from '@/Components/ui';

interface CopyButtonProps extends Omit<ButtonProps, 'onClick' | 'children'> {
    value: string;
    label?: string;
    copiedLabel?: string;
}

/** Copies `value` to the clipboard and confirms it for two seconds. The value is never logged. */
export function CopyButton({
    value,
    label = 'Salin',
    copiedLabel = 'Tersalin',
    variant = 'outline',
    size = 'sm',
    ...buttonProps
}: CopyButtonProps) {
    const [status, setStatus] = useState<'idle' | 'copied' | 'failed'>('idle');

    useEffect(() => {
        if (status === 'idle') return;

        const timer = setTimeout(() => setStatus('idle'), 2000);

        return () => clearTimeout(timer);
    }, [status]);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setStatus('copied');
        } catch {
            setStatus('failed');
        }
    };

    return (
        <>
            <Button
                type="button"
                variant={variant}
                size={size}
                onClick={() => void copy()}
                {...buttonProps}
            >
                {status === 'copied' ? copiedLabel : status === 'failed' ? 'Gagal menyalin' : label}
            </Button>
            <span role="status" className="sr-only">
                {status === 'copied'
                    ? copiedLabel
                    : status === 'failed'
                      ? 'Gagal menyalin, salin manual.'
                      : ''}
            </span>
        </>
    );
}
