import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import FilePondPluginFileValidateSize from 'filepond-plugin-file-validate-size';
import FilePondPluginFileValidateType from 'filepond-plugin-file-validate-type';
import FilePondPluginImagePreview from 'filepond-plugin-image-preview';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { registerPlugin } from 'react-filepond';
import { ToastProvider } from '@/Components/ui';
import { ErrorBoundary } from '@/Components/composite/ErrorBoundary';

// Product name, not env: existing .env files still carry VITE_APP_NAME=Laravel.
const appName = 'Inbils';

registerPlugin(
    FilePondPluginFileValidateSize,
    FilePondPluginFileValidateType,
    FilePondPluginImagePreview,
);

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.tsx`, import.meta.glob('./Pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <ErrorBoundary>
                <ToastProvider>
                    <App {...props} />
                </ToastProvider>
            </ErrorBoundary>,
        );
    },
    progress: {
        // Theme token, so the bar follows light/dark: 5.2:1 on light and 5.4:1 on dark backgrounds.
        // The former fixed #4B5563 measured 2.65:1 on the dark background, below the 3:1 non-text minimum.
        color: 'hsl(var(--primary))',
    },
});
