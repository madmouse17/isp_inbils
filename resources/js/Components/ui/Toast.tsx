import React, {
    createContext,
    useContext,
    useState,
    useCallback,
    useLayoutEffect,
    useRef,
} from 'react';
import { AlertCircle, AlertTriangle, CheckCircle2, Info, X } from 'lucide-react';
import { cn } from '@/lib/utils';

interface ToastMessage {
    id: number;
    title: string;
    description?: string;
    variant?: 'info' | 'success' | 'warning' | 'danger';
}

interface ToastContextValue {
    toast: (msg: Omit<ToastMessage, 'id'>) => void;
}

const ToastContext = createContext<ToastContextValue | null>(null);

export function useToast() {
    const ctx = useContext(ToastContext);
    if (!ctx) throw new Error('useToast must be used within ToastProvider');
    return ctx;
}

/**
 * A bar pinned to the top of the viewport (the support banner, the subsidiary ribbon) tells the toaster how tall it
 * is through a CSS variable, so a toast opens below the bar and never covers its buttons.
 */
export function useToastInset(
    variable: '--support-banner-height' | '--parent-ribbon-height',
    ref: React.RefObject<HTMLElement | null>,
) {
    useLayoutEffect(() => {
        const bar = ref.current;
        if (!bar) return;

        const root = document.documentElement.style;
        const publish = () => root.setProperty(variable, `${bar.offsetHeight}px`);
        publish();
        // The bar wraps onto more lines on a narrow screen, so its height is not fixed.
        const observer = new ResizeObserver(publish);
        observer.observe(bar);

        return () => {
            observer.disconnect();
            root.removeProperty(variable);
        };
    }, [variable, ref]);
}

/*
 * Every variant has its own icon, so colour is never the only signal. Danger is the loud one: heavier
 * border, tinted surface, bold title and role="alert" (assertive); the others announce politely.
 */
const variantStyles = {
    info: {
        Icon: Info,
        box: 'border-border',
        icon: 'text-sky-700 dark:text-sky-400',
        title: 'font-semibold',
    },
    success: {
        Icon: CheckCircle2,
        box: 'border-emerald-600/60 dark:border-emerald-500/60',
        icon: 'text-emerald-700 dark:text-emerald-400',
        title: 'font-semibold',
    },
    warning: {
        Icon: AlertTriangle,
        box: 'border-amber-600/70 dark:border-amber-500/70',
        icon: 'text-amber-700 dark:text-amber-400',
        title: 'font-semibold',
    },
    danger: {
        Icon: AlertCircle,
        box: 'border-2 border-destructive-text bg-red-50 dark:bg-red-950/40',
        icon: 'text-destructive-text',
        title: 'font-bold',
    },
} as const;

interface ToastProps {
    message: ToastMessage;
    onDismiss: (id: number) => void;
}

function Toast({ message, onDismiss }: ToastProps) {
    const variant = message.variant || 'info';
    const { Icon, box, icon, title } = variantStyles[variant];
    return (
        <div
            className={cn(
                'flex w-80 max-w-[calc(100vw-2rem)] items-start gap-3 rounded-lg border bg-card p-4 text-card-foreground shadow-md transition-all',
                box,
            )}
            role={variant === 'danger' ? 'alert' : 'status'}
        >
            <Icon className={cn('mt-0.5 h-5 w-5 shrink-0', icon)} aria-hidden="true" />
            <div className="min-w-0 flex-1">
                <p className={cn('text-sm text-foreground', title)}>{message.title}</p>
                {message.description && (
                    <p
                        className={cn(
                            'mt-1 text-sm',
                            variant === 'danger' ? 'text-foreground' : 'text-muted-foreground',
                        )}
                    >
                        {message.description}
                    </p>
                )}
            </div>
            <button
                onClick={() => onDismiss(message.id)}
                className="-mt-2 -mr-2 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:text-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden sm:h-8 sm:w-8 sm:pointer-coarse:h-11 sm:pointer-coarse:w-11"
                aria-label="Tutup notifikasi"
            >
                <X className="h-4 w-4" aria-hidden="true" />
            </button>
        </div>
    );
}

interface ToastProviderProps {
    children: React.ReactNode;
}

export function ToastProvider({ children }: ToastProviderProps) {
    const [messages, setMessages] = useState<ToastMessage[]>([]);
    const nextId = useRef(0);

    const toast = useCallback((msg: Omit<ToastMessage, 'id'>) => {
        const id = nextId.current++;
        setMessages((prev) => [...prev, { ...msg, id }]);
        setTimeout(() => {
            setMessages((prev) => prev.filter((m) => m.id !== id));
        }, 4000);
    }, []);

    const onDismiss = useCallback((id: number) => {
        setMessages((prev) => prev.filter((m) => m.id !== id));
    }, []);

    return (
        <ToastContext.Provider value={{ toast }}>
            {children}
            {/*
             * One step above the overlays of dialogs and sheets (z-50), so a toast raised while one is open stays
             * readable. `aria-live` also keeps it announced then: while a modal is open Radix marks the rest of the
             * page aria-hidden but skips every element that carries aria-live, and this region is always mounted.
             */}
            <div
                aria-live="polite"
                aria-label="Notifikasi"
                data-toast-region
                onPointerDown={(event) => event.stopPropagation()}
                className="pointer-events-none fixed top-[calc(1rem+var(--support-banner-height,0px)+var(--parent-ribbon-height,0px))] right-4 z-60 flex flex-col gap-2"
            >
                {messages.map((m) => (
                    <div key={m.id} className="pointer-events-auto">
                        <Toast message={m} onDismiss={onDismiss} />
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}
