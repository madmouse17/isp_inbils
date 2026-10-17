import { useMemo } from 'react';
import type { ReactNode } from 'react';
import { X } from 'lucide-react';
import { NativeSelect, RemoteSearchSelect } from '@/Components/composite';
import type { RemoteOption } from '@/Components/composite';
import { IconButton, SearchSelect } from '@/Components/ui';
import { ANNOUNCEMENT_AUDIENCE, labelOf } from '@/lib/statuses';
import type { Target } from '../types';

/** What the dialogs choose targets from, taken from the page props. */
export interface TargetingChoices {
    audiences: string[];
    /** Every active package category. */
    categories: Target[];
    /** The server's answer to the last location search; undefined before the first one. */
    locationOptions: RemoteOption[] | undefined;
}

interface TargetingFieldsProps {
    idPrefix: string;
    choices: TargetingChoices;
    audience: string;
    onAudienceChange: (audience: string) => void;
    locations: Target[];
    onLocationsChange: (next: Target[]) => void;
    categories: Target[];
    onCategoriesChange: (next: Target[]) => void;
    errors: { audience?: string; locations?: string; categories?: string };
}

/**
 * Who the row is for: every customer, or those with an active or suspended subscription that fits
 * every list filled in (a location with all below it, a package category). A picker adds one target
 * to its list and starts empty again; each target in the list has its own remove button.
 */
export function TargetingFields({
    idPrefix,
    choices,
    audience,
    onAudienceChange,
    locations,
    onLocationsChange,
    categories,
    onCategoriesChange,
    errors,
}: TargetingFieldsProps) {
    const audienceOptions = useMemo(
        () =>
            choices.audiences.map((value) => ({
                value,
                label: labelOf(ANNOUNCEMENT_AUDIENCE, value),
            })),
        [choices.audiences],
    );
    const locationOptions = useMemo(
        () =>
            choices.locationOptions?.filter(
                (option) =>
                    !locations.some((location) => String(location.id) === String(option.value)),
            ),
        [choices.locationOptions, locations],
    );
    const categoryOptions = useMemo(
        () =>
            choices.categories
                .filter((category) => !categories.some((chosen) => chosen.id === category.id))
                .map((category) => ({
                    value: String(category.id),
                    label: category.name,
                    description: category.code,
                })),
        [choices.categories, categories],
    );

    const addLocation = (value: string) => {
        const option = choices.locationOptions?.find((entry) => String(entry.value) === value);
        if (!option || locations.some((location) => String(location.id) === value)) return;

        onLocationsChange([
            ...locations,
            {
                id: Number(option.value),
                name: option.label,
                description: option.description ?? undefined,
            },
        ]);
    };

    const addCategory = (value: string) => {
        const category = choices.categories.find((entry) => String(entry.id) === value);
        if (category) onCategoriesChange([...categories, category]);
    };

    return (
        <div className="space-y-3">
            <NativeSelect
                id={`${idPrefix}-audience`}
                label="Sasaran"
                value={audience}
                onChange={(event) => onAudienceChange(event.target.value)}
                options={audienceOptions}
                error={errors.audience}
            />
            {audience === 'targeted' ? (
                <div className="space-y-4 rounded-lg border border-border p-4">
                    <p className="text-sm text-muted-foreground">
                        Pelanggan cocok bila punya langganan aktif atau ditangguhkan yang memenuhi
                        semua daftar yang diisi. Di dalam satu daftar, salah satu pilihan sudah
                        cukup.
                    </p>
                    <TargetList
                        noun="lokasi"
                        items={locations}
                        onRemove={(item) =>
                            onLocationsChange(locations.filter((entry) => entry.id !== item.id))
                        }
                        error={errors.locations}
                    >
                        <RemoteSearchSelect
                            id={`${idPrefix}-location`}
                            field="location"
                            label="Lokasi"
                            value=""
                            onChange={addLocation}
                            options={locationOptions}
                            placeholder="Cari nama lokasi"
                            hint="Lokasi yang dipilih dan semua lokasi di bawahnya ikut menjadi sasaran."
                        />
                    </TargetList>
                    <TargetList
                        noun="kategori"
                        items={categories}
                        onRemove={(item) =>
                            onCategoriesChange(categories.filter((entry) => entry.id !== item.id))
                        }
                        error={errors.categories}
                    >
                        <SearchSelect
                            id={`${idPrefix}-category`}
                            label="Kategori paket"
                            value=""
                            onChange={addCategory}
                            options={categoryOptions}
                            placeholder="Cari kategori paket"
                        />
                    </TargetList>
                </div>
            ) : null}
        </div>
    );
}

interface TargetListProps {
    /** `lokasi` or `kategori`, for the remove buttons. */
    noun: string;
    items: Target[];
    onRemove: (item: Target) => void;
    error?: string;
    /** The picker that adds to the list. */
    children: ReactNode;
}

function TargetList({ noun, items, onRemove, error, children }: TargetListProps) {
    return (
        <div className="space-y-2">
            {children}
            {error ? <p className="text-sm text-destructive-text">{error}</p> : null}
            {items.length > 0 ? (
                <ul className="divide-y divide-border rounded-md border border-border">
                    {items.map((item) => (
                        <li key={item.id} className="flex items-center justify-between gap-2 pl-3">
                            <span className="min-w-0 py-2 text-sm wrap-break-word">
                                <span className="font-medium">{item.name}</span>
                                {item.description || item.code ? (
                                    <span className="block text-xs text-muted-foreground">
                                        {item.description ?? item.code}
                                    </span>
                                ) : null}
                            </span>
                            <IconButton
                                size="sm"
                                label={`Hapus ${noun} ${item.name}`}
                                onClick={() => onRemove(item)}
                            >
                                <X className="size-4" aria-hidden="true" />
                            </IconButton>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-sm text-muted-foreground">Belum ada {noun} yang dipilih.</p>
            )}
        </div>
    );
}
