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
}: FileUploadProps) {
    const inputId = label?.toLowerCase().replace(/\s+/g, '-');

    return (
        <div className={cn('w-full', className)}>
            {label && (
                <Label htmlFor={inputId} required={required}>
                    {label}
                </Label>
            )}
            <div className="mt-1">
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
                    labelFileTypeNotAllowed="File type is not allowed"
                    fileValidateTypeLabelExpectedTypes="Allowed: {allTypes}"
                    labelMaxFileSizeExceeded="File is too large"
                    labelMaxFileSize="Maximum file size is {filesize}"
                    credits={false}
                    labelIdle='Drop file here or <span class="filepond--label-action">browse</span>'
                />
            </div>
            {error && <p className="mt-1 text-sm text-destructive">{error}</p>}
            {!error && hint && <p className="mt-1 text-sm text-muted-foreground">{hint}</p>}
        </div>
    );
}
