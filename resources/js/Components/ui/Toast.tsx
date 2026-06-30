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
    info: 'border-blue-200 bg-white dark:border-blue-800 dark:bg-surface-900',
    success: 'border-green-200 bg-white dark:border-green-800 dark:bg-surface-900',
    warning: 'border-amber-200 bg-white dark:border-amber-800 dark:bg-surface-900',
    danger: 'border-red-200 bg-white dark:border-red-800 dark:bg-surface-900',
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
                <p className="text-sm font-semibold text-surface-900 dark:text-surface-100">{message.title}</p>
                {message.description && (
                    <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">{message.description}</p>
                )}
            </div>
            <button
                onClick={() => onDismiss(message.id)}
                className="shrink-0 rounded-md p-1 text-surface-400 hover:text-surface-600 focus:outline-none focus:ring-2 focus:ring-brand-500/50 dark:hover:text-surface-300"
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
