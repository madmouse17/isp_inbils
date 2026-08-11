export type ExportPdfServerOptions = {
    /** Server export URL (GET). Current list query params are merged in. */
    url: string;
    params?: Record<string, string | number | boolean | null | undefined>;
    /** Defaults to GET navigation (full download). */
    method?: 'GET';
};

export type ExportPdfHtmlOptions = {
    /** Fallback HTML print foundation when no server PDF route exists. */
    html: string;
    filename?: string;
};

/**
 * Open/download a server-generated PDF (or HTML fallback from ExportQuery).
 * Foundation only — does not render PDF in the browser.
 */
export function exportPdf(options: ExportPdfServerOptions | ExportPdfHtmlOptions): void {
    if ('html' in options) {
        const filename = options.filename?.endsWith('.html')
            ? options.filename
            : `${options.filename ?? 'export'}.html`;
        const blob = new Blob([options.html], {
            type: 'text/html;charset=utf-8',
        });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
        return;
    }

    const url = new URL(options.url, window.location.origin);
    if (options.params) {
        Object.entries(options.params).forEach(([key, value]) => {
            if (value === null || value === undefined) {
                return;
            }
            url.searchParams.set(key, String(value));
        });
    }

    // Full navigation so Content-Disposition attachment is honored.
    window.location.assign(url.toString());
}

/**
 * Merge current window query string into a server export URL.
 */
export function exportPdfWithCurrentFilters(
    path: string,
    extra: Record<string, string | number | boolean | null | undefined> = {},
): void {
    const current = new URL(window.location.href);
    const params: Record<string, string> = {};
    current.searchParams.forEach((value, key) => {
        // Drop pagination noise for full filtered export.
        if (key === 'page') {
            return;
        }
        params[key] = value;
    });
    Object.entries(extra).forEach(([key, value]) => {
        if (value === null || value === undefined) {
            return;
        }
        params[key] = String(value);
    });
    exportPdf({ url: path, params });
}
