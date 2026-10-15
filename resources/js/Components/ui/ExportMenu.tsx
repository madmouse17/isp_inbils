import React from 'react';
import { Download } from 'lucide-react';
import { Button } from '@/Components/ui/Button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';

export type ExportFormat = 'csv' | 'pdf';

export interface ExportMenuProps {
    onExport?: (format: ExportFormat) => void;
    /** Server export base URL (appends format query). Back-compat with legacy ExportMenu. */
    exportUrl?: string;
    /** Query params forwarded to the export URL. Back-compat with legacy ExportMenu. */
    params?: Record<string, string | number | boolean | null | undefined>;
    /** Resource key for compatibility with residual consumers. */
    resource?: string;
    formats?: ExportFormat[];
    disabled?: boolean;
    label?: string;
    /** Hides the menu entirely when false. Back-compat with legacy ExportMenu. */
    canExport?: boolean;
    className?: string;
}

const LABELS: Record<ExportFormat, string> = {
    csv: 'Ekspor CSV',
    pdf: 'Ekspor PDF',
};

export function ExportMenu({
    onExport,
    exportUrl,
    params,
    resource: _resource,
    formats = ['csv'],
    disabled = false,
    label = 'Ekspor',
    canExport = true,
    className,
}: ExportMenuProps) {
    const handleExport = (format: ExportFormat) => {
        if (onExport) {
            onExport(format);
            return;
        }
        if (exportUrl) {
            const query = new URLSearchParams();
            query.set('format', format);
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
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={disabled || (!onExport && !exportUrl)}
                    leftIcon={<Download className="h-4 w-4" />}
                    className={className}
                >
                    {label}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {formats.map((format) => (
                    <DropdownMenuItem key={format} onSelect={() => handleExport(format)}>
                        {LABELS[format]}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export default ExportMenu;
