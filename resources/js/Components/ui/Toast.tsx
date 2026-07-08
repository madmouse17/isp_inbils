import React, { createContext, useContext, useState, useCallback, useRef } from 'react';
import { cn } from '@/lib/utils';
import { XMarkIcon } from '@heroicons/react/20/solid';

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

const variantStyles = {
    info: 'border-border bg-card text-card-foreground',
    success: 'border-green-200 bg-card text-card-foreground dark:border-green-800',
    warning: 'border-amber-200 bg-card text-card-foreground dark:border-amber-800',
    danger: 'border-destructive/30 bg-card text-card-foreground',
} as const;

interface ToastProps {
    message: ToastMessage;
    onDismiss: (id: number) => void;
}

function Toast({ message, onDismiss }: ToastProps) {
    const variant = message.variant || 'info';
    return (
        <div
            className={cn(
                'flex w-80 items-start gap-3 rounded-lg border p-4 shadow-lg transition-all',
                variantStyles[variant],
            )}
            role="status"
            aria-live="polite"
        >
            <div className="flex-1">
                <p className="text-sm font-semibold text-foreground">{message.title}</p>
                {message.description && (
                    <p className="mt-1 text-sm text-muted-foreground">{message.description}</p>
                )}
            </div>
            <button
                onClick={() => onDismiss(message.id)}
                className="shrink-0 rounded-md p-1 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                aria-label="Close notification"
            >
                <XMarkIcon className="h-4 w-4" />
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
            <div
                aria-live="polite"
                aria-label="Notifications"
                className="pointer-events-none fixed right-4 top-4 z-50 flex flex-col gap-2"
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
