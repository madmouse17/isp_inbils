import React from 'react';
import { Download } from 'lucide-react';
import { Button } from '@/Components/ui/Button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/Dropdown';

export type ExportFormat = 'csv' | 'xlsx' | 'pdf';

export interface ExportMenuProps {
    onExport?: (format: ExportFormat) => void;
    formats?: ExportFormat[];
    disabled?: boolean;
    label?: string;
    className?: string;
}

const LABELS: Record<ExportFormat, string> = {
    csv: 'Export CSV',
    xlsx: 'Export Excel',
    pdf: 'Export PDF',
};

export function ExportMenu({
    onExport,
    formats = ['csv', 'xlsx'],
    disabled = false,
    label = 'Export',
    className,
}: ExportMenuProps) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={disabled || !onExport}
                    leftIcon={<Download className="h-4 w-4" />}
                    className={className}
                >
                    {label}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {formats.map((format) => (
                    <DropdownMenuItem key={format} onSelect={() => onExport?.(format)}>
                        {LABELS[format]}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export default ExportMenu;
