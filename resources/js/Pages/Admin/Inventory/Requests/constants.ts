export const returnStatusLabelMap: Record<string, string> = {
    awaiting_declaration: 'Menunggu Deklarasi Teknisi',
    awaiting_receipt: 'Menunggu Penerimaan Gudang',
    correction_required: 'Perlu Koreksi / Ditolak Gudang',
    exception_open: 'Diskrepansi (Perlu Resolusi)',
    reconciled: 'Selesai Rekonsiliasi (Diterima)',
    resolved: 'Diskrepansi Diselesaikan',
};

export const returnStatusVariantMap: Record<
    string,
    'neutral' | 'info' | 'warning' | 'success' | 'danger'
> = {
    awaiting_declaration: 'info',
    awaiting_receipt: 'warning',
    correction_required: 'danger',
    exception_open: 'danger',
    reconciled: 'success',
    resolved: 'success',
};
