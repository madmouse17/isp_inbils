import { Input, NativeSelect, PhoneInput, Switch, Textarea } from '@/Components/ui';

export interface CustomerDataValue {
    code: string;
    name: string;
    type: string;
    email: string;
    phone: string;
    tax_id: string;
    contact_person: string;
    notes: string;
    is_active: boolean;
}

interface CustomerDataFieldsProps {
    value: CustomerDataValue;
    onChange: <Key extends keyof CustomerDataValue>(key: Key, next: CustomerDataValue[Key]) => void;
    errors?: Partial<Record<keyof CustomerDataValue, string>>;
    /** When true every field is rendered read-only / disabled (viewer permission). */
    readOnly?: boolean;
    /** Phone field hint shown on create (password = phone). */
    phoneHint?: string;
}

export function CustomerDataFields({
    value,
    onChange,
    errors = {},
    readOnly = false,
    phoneHint,
}: CustomerDataFieldsProps) {
    return (
        <div className="grid gap-4 md:grid-cols-2">
            <Input
                label="Code"
                value={value.code}
                onChange={(event) => onChange('code', event.target.value)}
                error={errors.code}
                required
                placeholder="CUS-2026-00001"
                readOnly={readOnly}
            />
            <Input
                label="Name"
                value={value.name}
                onChange={(event) => onChange('name', event.target.value)}
                error={errors.name}
                required
                readOnly={readOnly}
            />
            <NativeSelect
                label="Type"
                value={value.type}
                onChange={(event) => onChange('type', event.target.value)}
                error={errors.type}
                disabled={readOnly}
            >
                <option value="Individual">Individual</option>
                <option value="Company">Company</option>
            </NativeSelect>
            <Input
                label="Email"
                type="email"
                value={value.email}
                onChange={(event) => onChange('email', event.target.value)}
                error={errors.email}
                required
                readOnly={readOnly}
            />
            <PhoneInput
                label="Phone"
                value={value.phone}
                onChange={(next) => onChange('phone', next)}
                error={errors.phone}
                hint={phoneHint}
                required
                readOnly={readOnly}
            />
            <Input
                label="Tax ID (NPWP)"
                value={value.tax_id}
                onChange={(event) => onChange('tax_id', event.target.value)}
                error={errors.tax_id}
                required={value.type === 'Company'}
                readOnly={readOnly}
            />
            <Input
                label="Contact Person"
                value={value.contact_person}
                onChange={(event) => onChange('contact_person', event.target.value)}
                error={errors.contact_person}
                required={value.type === 'Company'}
                readOnly={readOnly}
            />
            <div className="flex items-end">
                <Switch
                    label="Active"
                    checked={value.is_active}
                    onCheckedChange={(checked) => onChange('is_active', checked)}
                    disabled={readOnly}
                />
            </div>
            <Textarea
                className="md:col-span-2"
                label="Notes"
                value={value.notes}
                onChange={(event) => onChange('notes', event.target.value)}
                error={errors.notes}
                rows={3}
                readOnly={readOnly}
            />
        </div>
    );
}
