import { useState } from 'react';
import { ArrowDownTrayIcon, ArrowTopRightOnSquareIcon } from '@heroicons/react/24/outline';
import { Button } from '@/Components/ui';

export interface MediaFile {
    /** Authorized route of the file (`admin.media.show`); never a storage path. */
    url: string;
    mime_type?: string | null;
    original_name?: string | null;
}

/**
 * Thumbnail and Open for images (the media route renders only raster images in place), Download for
 * every file: anything else, PDF included, is served as a download.
 */
export function MediaPreview({ media }: { media: MediaFile }) {
    const [broken, setBroken] = useState(false);
    const name = media.original_name ?? 'file';
    const isImage = media.mime_type?.startsWith('image/') ?? false;

    return (
        <div className="mt-2 space-y-2">
            {isImage && !broken && (
                <img
                    src={media.url}
                    alt={name}
                    loading="lazy"
                    onError={() => setBroken(true)}
                    className="max-h-40 w-full rounded-md border border-border bg-muted/30 object-contain"
                />
            )}
            <div className="flex flex-wrap gap-2">
                {isImage && (
                    <Button asChild size="sm" variant="outline">
                        <a
                            href={media.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label={`Open ${name} in a new tab`}
                        >
                            <ArrowTopRightOnSquareIcon className="h-4 w-4" aria-hidden="true" />
                            Open
                        </a>
                    </Button>
                )}
                <Button asChild size="sm" variant="outline">
                    <a href={media.url} download aria-label={`Download ${name}`}>
                        <ArrowDownTrayIcon className="h-4 w-4" aria-hidden="true" />
                        Download
                    </a>
                </Button>
            </div>
        </div>
    );
}
