import { Button } from '@/Components/ui';

type ExportFormat = 'csv' | 'xlsx' | 'pdf';

type ExportMenuProps = {
    onExport?: (format: ExportFormat) => void;
    /** Server export base URL (appends format query). */
    exportUrl?: string;
    /** Resource key for compatibility with residual consumers. */
    resource?: string;
    formats?: ExportFormat[];
    label?: string;
    disabled?: boolean;
    className?: string;
};

export function ExportMenu({
    onExport,
    exportUrl,
    resource: _resource,
    formats = ['csv', 'xlsx'],
    label = 'Export',
    disabled,
    className,
}: ExportMenuProps) {
    const handle = (fmt: ExportFormat) => {
        if (onExport) {
            onExport(fmt);
            return;
        }
        if (exportUrl) {
            const url = exportUrl.includes('?')
                ? `${exportUrl}&format=${fmt}`
                : `${exportUrl}?format=${fmt}`;
            window.location.assign(url);
        }
    };

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
