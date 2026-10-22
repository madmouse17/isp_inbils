import { useMemo, useState } from 'react';
import { Button, SearchSelect } from '@/Components/ui';
import type { SearchSelectOption } from '@/Components/ui';
import { QuickCreateDialog, type CreatedOption, type QuickCreateKind } from './QuickCreateDialog';

export interface ProfileRow {
    id: string;
    name: string;
    download_mbps?: number;
    upload_mbps?: number;
}

type ProfileKey = 'bandwidth_profile_id' | 'speed_profile_id' | 'sla_tier_id';

interface PackageProfilePickersProps {
    values: Record<ProfileKey, string>;
    onChange: (key: ProfileKey, value: string) => void;
    errors: Partial<Record<ProfileKey, string>>;
    bandwidthProfiles: ProfileRow[];
    speedProfiles: ProfileRow[];
    slaTiers: ProfileRow[];
}

const toOptions = (rows: ProfileRow[]): SearchSelectOption[] =>
    rows.map((row) => ({ value: String(row.id), label: row.name }));

const FIELD_OF: Record<QuickCreateKind, ProfileKey> = {
    bandwidth: 'bandwidth_profile_id',
    speed: 'speed_profile_id',
    sla: 'sla_tier_id',
};

/**
 * Bandwidth profile, speed profile and SLA tier of a package form, each with "+ Baru": the row is created in a
 * dialog and selected, so the admin never leaves a half-filled package. A new speed profile starts from the speeds
 * of the bandwidth profile picked above it. Render inside the form's grid; the dialog adds no cell.
 */
export function PackageProfilePickers({
    values,
    onChange,
    errors,
    bandwidthProfiles,
    speedProfiles,
    slaTiers,
}: PackageProfilePickersProps) {
    const [creating, setCreating] = useState<QuickCreateKind | null>(null);
    const [created, setCreated] = useState<Record<QuickCreateKind, ProfileRow[]>>({
        bandwidth: [],
        speed: [],
        sla: [],
    });

    const options = useMemo(
        () => ({
            bandwidth: toOptions([...bandwidthProfiles, ...created.bandwidth]),
            speed: toOptions([...speedProfiles, ...created.speed]),
            sla: toOptions([...slaTiers, ...created.sla]),
        }),
        [bandwidthProfiles, speedProfiles, slaTiers, created],
    );

    const chosenBandwidth = [...bandwidthProfiles, ...created.bandwidth].find(
        (row) => String(row.id) === values.bandwidth_profile_id,
    );

    const handleCreated = (
        kind: QuickCreateKind,
        option: CreatedOption,
        submitted: Record<string, string | boolean>,
    ) => {
        const row: ProfileRow = {
            id: option.id,
            name: option.label,
            ...(kind === 'bandwidth' && {
                download_mbps: Number(submitted.download_mbps),
                upload_mbps: Number(submitted.upload_mbps),
            }),
        };
        setCreated((current) => ({ ...current, [kind]: [...current[kind], row] }));
        onChange(FIELD_OF[kind], option.id);
    };

    const picker = (kind: QuickCreateKind, id: string, label: string, placeholder: string) => (
        <div className="space-y-1">
            <SearchSelect
                id={id}
                label={label}
                value={values[FIELD_OF[kind]]}
                onChange={(value) => onChange(FIELD_OF[kind], value)}
                options={options[kind]}
                placeholder={placeholder}
                error={errors[FIELD_OF[kind]]}
                required
            />
            <Button type="button" variant="ghost" size="sm" onClick={() => setCreating(kind)}>
                + Baru
            </Button>
        </div>
    );

    return (
        <>
            {picker('bandwidth', 'package-bandwidth-profile', 'Profil bandwidth', 'Pilih profil')}
            {picker('speed', 'package-speed-profile', 'Profil kecepatan', 'Pilih profil')}
            {picker('sla', 'package-sla-tier', 'Tingkat SLA', 'Pilih tingkat')}
            <QuickCreateDialog
                kind={creating}
                onClose={() => setCreating(null)}
                onCreated={handleCreated}
                speedDefaults={{
                    download: chosenBandwidth?.download_mbps ?? null,
                    upload: chosenBandwidth?.upload_mbps ?? null,
                }}
            />
        </>
    );
}
