import { Button } from '@/Components/ui';

type ExportFormat = 'csv' | 'xlsx' | 'pdf';

type ExportMenuProps = {
    onExport?: (format: ExportFormat) => void;
    /** Server export base URL (appends format query). */
    exportUrl?: string;
    /** Query params forwarded to the export URL. */
    params?: Record<string, string | number | boolean | null | undefined>;
    /** Resource key for compatibility with residual consumers. */
    resource?: string;
    formats?: ExportFormat[];
    label?: string;
    disabled?: boolean;
    canExport?: boolean;
    className?: string;
};

export function ExportMenu({
    onExport,
    exportUrl,
    params,
    resource: _resource,
    formats = ['csv', 'xlsx'],
    label = 'Export',
    disabled,
    canExport = true,
    className,
}: ExportMenuProps) {
    const handle = (fmt: ExportFormat) => {
        if (onExport) {
            onExport(fmt);
            return;
        }
        if (exportUrl) {
            const query = new URLSearchParams();
            query.set('format', fmt);
            for (const [key, value] of Object.entries(params ?? {})) {
                if (value === undefined || value === null || value === '') continue;
                query.set(key, String(value));
            }
            const url = exportUrl.includes('?')
                ? `${exportUrl}&${query.toString()}`
                : `${exportUrl}?${query.toString()}`;
            window.location.assign(url);
        }
    };

    if (!canExport) return null;

    return (
        <div className={className}>
            {formats.map((fmt) => (
                <Button
                    key={fmt}
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={disabled}
                    className="mr-2"
                    onClick={() => handle(fmt)}
                >
                    {label} {fmt.toUpperCase()}
                </Button>
            ))}
        </div>
    );
}

export default ExportMenu;
