import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/react';
import FilePondPluginFileValidateSize from 'filepond-plugin-file-validate-size';
import FilePondPluginFileValidateType from 'filepond-plugin-file-validate-type';
import FilePondPluginImagePreview from 'filepond-plugin-image-preview';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { registerPlugin } from 'react-filepond';
import { ToastProvider } from '@/Components/ui';
import { ErrorBoundary } from '@/Components/composite/ErrorBoundary';
import { installNativeValidity } from '@/lib/nativeValidity';

// Product name, not env: existing .env files still carry VITE_APP_NAME=Laravel.
const appName = 'Inbils';

registerPlugin(
    FilePondPluginFileValidateSize,
    FilePondPluginFileValidateType,
    FilePondPluginImagePreview,
);

// Browser bubbles for native constraints follow the browser language; this makes them Indonesian everywhere.
installNativeValidity();

// A write names the company its page was rendered in; the server refuses it when another tab switched company since.
let renderedCompanyId: string | null = null;
const rememberCompany = (props: { company?: { id?: string } | null }) => {
    renderedCompanyId = props.company?.id ?? null;
};
router.on('navigate', (event) => rememberCompany(event.detail.page.props));
router.on('success', (event) => rememberCompany(event.detail.page.props));

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    defaults: {
        visitOptions: (_href, options) =>
            renderedCompanyId && options.method && options.method !== 'get'
                ? { headers: { ...options.headers, 'X-Inbils-Company': renderedCompanyId } }
                : {},
    },
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.tsx`, import.meta.glob('./Pages/**/*.tsx')),
    setup({ el, App, props }) {
        rememberCompany(props.initialPage.props);

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
