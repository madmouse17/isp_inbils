import { useState } from 'react';
import { Download, ExternalLink } from 'lucide-react';
import { Button } from '@/Components/ui';

export interface MediaFile {
    /** Authorized route of the file (`admin.media.show`); never a storage path. */
    url: string;
    mime_type?: string | null;
    original_name?: string | null;
}

/**
 * Thumbnail and Buka for images (the media route renders only raster images in place), Unduh for
 * every file: anything else, PDF included, is served as a download.
 */
export function MediaPreview({ media }: { media: MediaFile }) {
    const [broken, setBroken] = useState(false);
    const name = media.original_name ?? 'berkas';
    const isImage = media.mime_type?.startsWith('image/') ?? false;

    return (
        <div className="space-y-2 mt-2">
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
                            aria-label={`Buka ${name} di tab baru`}
                        >
                            <ExternalLink className="h-4 w-4" aria-hidden="true" />
                            Buka
                        </a>
                    </Button>
                )}
                <Button asChild size="sm" variant="outline">
                    <a href={media.url} download aria-label={`Unduh ${name}`}>
                        <Download className="h-4 w-4" aria-hidden="true" />
                        Unduh
                    </a>
                </Button>
            </div>
        </div>
    );
}
