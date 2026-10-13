import { Component, type ErrorInfo, type ReactNode } from 'react';
import { ErrorState } from '@/Components/composite/ResourceState';

interface ErrorBoundaryProps {
    children: ReactNode;
}

interface ErrorBoundaryState {
    failed: boolean;
}

/**
 * Last line of defence at the root: a page that throws while rendering would otherwise leave a blank
 * screen. A reload is the honest retry, because the broken page state cannot be trusted.
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
    state: ErrorBoundaryState = { failed: false };

    static getDerivedStateFromError(): ErrorBoundaryState {
        return { failed: true };
    }

    componentDidCatch(error: Error, info: ErrorInfo) {
        console.error(error, info.componentStack);
    }

    render() {
        if (!this.state.failed) {
            return this.props.children;
        }

        return (
            <main className="flex min-h-screen items-center justify-center p-6">
                <ErrorState
                    className="max-w-lg"
                    title="Halaman tidak dapat ditampilkan"
                    description="Terjadi kesalahan saat menampilkan halaman ini. Muat ulang halaman. Jika masalah berlanjut, hubungi administrator."
                    onRetry={() => window.location.reload()}
                    retryLabel="Muat ulang halaman"
                />
            </main>
        );
    }
}
