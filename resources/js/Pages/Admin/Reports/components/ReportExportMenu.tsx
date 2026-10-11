import { ExportMenu } from '@/Components/ui';
import type { ExportMenuProps } from '@/Components/ui';

interface ReportExportMenuProps {
    url: string;
    /** The filters applied on screen, so the file holds what the page shows. */
    params: ExportMenuProps['params'];
    /** `report.export` plus the report's own capability; the server checks both again. */
    canExport: boolean;
}

/** Reports export CSV and PDF only: the shared menu defaults to a spreadsheet format that does not exist. */
export function ReportExportMenu({ url, params, canExport }: ReportExportMenuProps) {
    return (
        <ExportMenu
            exportUrl={url}
            params={params}
            formats={['csv', 'pdf']}
            canExport={canExport}
        />
    );
}
