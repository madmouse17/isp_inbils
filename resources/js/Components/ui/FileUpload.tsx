import type { FilePondFile } from 'filepond';
import { FilePond } from 'react-filepond';
import { cn } from '@/lib/utils';
import { Label } from './Label';

const defaultAcceptedFileTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

export interface FileUploadProps {
    label?: string;
    value: File | null;
    onChange: (file: File | null) => void;
    acceptedFileTypes?: string[];
    disabled?: boolean;
    error?: string;
    hint?: string;
    required?: boolean;
    className?: string;
    maxFileSize?: string;
    previewUrl?: string | null;
    previewName?: string;
    previewType?: string;
    previewSize?: number;
}

export function FileUpload({
    label,
    value,
    onChange,
    acceptedFileTypes = defaultAcceptedFileTypes,
    disabled,
    error,
    hint,
    required,
    className,
    maxFileSize = '10MB',
    previewUrl,
    previewName = 'Berkas saat ini',
    previewType,
}: FileUploadProps) {
    const inputId = label?.toLowerCase().replace(/\s+/g, '-');
    const fileType = previewType ?? inferMimeType(previewUrl);
    const canPreviewImage = previewUrl && fileType?.startsWith('image/');

    return (
        <div className={cn('w-full', className)}>
            {label && (
                <Label htmlFor={inputId} required={required}>
                    {label}
                </Label>
            )}
            <div className="mt-1">
                {previewUrl && !value && (
                    <div className="mb-2 overflow-hidden rounded-lg border border-border bg-background">
                        {canPreviewImage ? (
                            <img
                                src={previewUrl}
                                alt={previewName}
                                className="h-32 w-full object-contain p-3"
                            />
                        ) : (
                            <a
                                href={previewUrl}
                                target="_blank"
                                rel="noreferrer"
                                className="block px-3 py-2 text-sm font-medium text-primary hover:underline"
                            >
                                {previewName}
                            </a>
                        )}
                    </div>
                )}
                <FilePond
                    id={inputId}
                    files={value ? [value] : []}
                    onupdatefiles={(fileItems: FilePondFile[]) => {
                        const file = fileItems[0]?.file;
                        onChange(file instanceof File ? file : null);
                    }}
                    onaddfile={(fileError) => {
                        if (fileError) {
                            onChange(null);
                        }
                    }}
                    allowMultiple={false}
                    maxFiles={1}
                    disabled={disabled}
                    acceptedFileTypes={acceptedFileTypes}
                    maxFileSize={maxFileSize}
                    labelFileTypeNotAllowed="Jenis berkas tidak diizinkan"
                    fileValidateTypeLabelExpectedTypes="Diizinkan: {allTypes}"
                    labelMaxFileSizeExceeded="Berkas terlalu besar"
                    labelMaxFileSize="Ukuran berkas maksimum {filesize}"
                    credits={false}
                    labelIdle='Seret berkas ke sini atau <span class="filepond--label-action">telusuri</span>'
                    labelInvalidField="Ada berkas yang tidak valid"
                    labelFileWaitingForSize="Menunggu ukuran berkas"
                    labelFileSizeNotAvailable="Ukuran tidak tersedia"
                    labelFileLoading="Memuat"
                    labelFileLoadError="Gagal memuat berkas"
                    labelFileRemoveError="Gagal menghapus berkas"
                    labelTapToCancel="ketuk untuk membatalkan"
                    labelTapToRetry="ketuk untuk mencoba lagi"
                    labelButtonRemoveItem="Hapus"
                    labelButtonAbortItemLoad="Batalkan"
                    labelButtonRetryItemLoad="Coba lagi"
                />
            </div>
            {error && <p className="mt-1 text-sm text-destructive-text">{error}</p>}
            {!error && hint && <p className="mt-1 text-sm text-muted-foreground">{hint}</p>}
        </div>
    );
}

function inferMimeType(url?: string | null): string | undefined {
    if (!url) {
        return undefined;
    }

    const path = url.split('?')[0]?.toLowerCase() ?? '';

    if (path.endsWith('.jpg') || path.endsWith('.jpeg')) {
        return 'image/jpeg';
    }

    if (path.endsWith('.png')) {
        return 'image/png';
    }

    if (path.endsWith('.webp')) {
        return 'image/webp';
    }

    if (path.endsWith('.svg')) {
        return 'image/svg+xml';
    }

    if (path.endsWith('.pdf')) {
        return 'application/pdf';
    }

    return undefined;
}
