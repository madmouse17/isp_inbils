export type CsvCell = string | number | boolean | null | undefined;

export type CsvColumn<T> = {
    key: string;
    header: string;
    value?: (row: T) => CsvCell;
};

function escapeCell(value: CsvCell): string {
    if (value === null || value === undefined) {
        return '';
    }

    const text = String(value);
    const escaped = text.replace(/"/g, '""');
    return /[",\n\r]/.test(text) ? `"${escaped}"` : text;
}

export function rowsToCsv<T extends Record<string, unknown>>(
    rows: T[],
    columns: CsvColumn<T>[],
): string {
    const header = columns.map((column) => escapeCell(column.header)).join(',');
    const body = rows.map((row) =>
        columns
            .map((column) => {
                const raw = column.value ? column.value(row) : (row[column.key] as CsvCell);
                return escapeCell(raw);
            })
            .join(','),
    );

    return [header, ...body].join('\r\n');
}

export function downloadCsv(filename: string, csv: string): void {
    const blob = new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');

    try {
        anchor.href = url;
        anchor.download = filename.endsWith('.csv') ? filename : `${filename}.csv`;
        anchor.style.display = 'none';
        document.body.appendChild(anchor);
        anchor.click();
    } finally {
        anchor.remove();
        URL.revokeObjectURL(url);
    }
}

export function exportRowsAsCsv<T extends Record<string, unknown>>(
    filename: string,
    rows: T[],
    columns: CsvColumn<T>[],
): void {
    downloadCsv(filename, rowsToCsv(rows, columns));
}
