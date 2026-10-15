import { Input, NativeSelect, PhoneInput, Switch, Textarea } from '@/Components/ui';
import { CUSTOMER_TYPE, optionsOf } from '@/lib/statuses';
import { SequenceCodeField, type CodeSequence } from './SequenceCodeField';

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
    /** Phone field hint shown on create. */
    phoneHint?: string;
    codeSequence: CodeSequence;
    mode: 'create' | 'edit';
}

export function CustomerDataFields({
    value,
    onChange,
    errors = {},
    readOnly = false,
    phoneHint,
    codeSequence,
    mode,
}: CustomerDataFieldsProps) {
    return (
        <div className="grid gap-4 md:grid-cols-2">
            <SequenceCodeField
                label="Kode"
                value={value.code}
                onChange={(next) => onChange('code', next)}
                error={errors.code}
                sequence={codeSequence}
                mode={mode}
                readOnly={readOnly}
            />
            <Input
                label="Nama"
                value={value.name}
                onChange={(event) => onChange('name', event.target.value)}
                error={errors.name}
                required
                readOnly={readOnly}
            />
            <NativeSelect
                label="Tipe"
                value={value.type}
                onChange={(event) => onChange('type', event.target.value)}
                error={errors.type}
                options={optionsOf(CUSTOMER_TYPE)}
                disabled={readOnly}
            />
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
                label="Telepon"
                value={value.phone}
                onChange={(next) => onChange('phone', next)}
                error={errors.phone}
                hint={phoneHint}
                required
                readOnly={readOnly}
            />
            <Input
                label="NPWP"
                value={value.tax_id}
                onChange={(event) => onChange('tax_id', event.target.value)}
                error={errors.tax_id}
                required={value.type === 'Company'}
                readOnly={readOnly}
            />
            <Input
                label="Narahubung"
                value={value.contact_person}
                onChange={(event) => onChange('contact_person', event.target.value)}
                error={errors.contact_person}
                required={value.type === 'Company'}
                readOnly={readOnly}
            />
            <div className="flex items-end">
                <Switch
                    label="Akun aktif"
                    checked={value.is_active}
                    onCheckedChange={(checked) => onChange('is_active', checked)}
                    disabled={readOnly}
                />
            </div>
            <Textarea
                className="md:col-span-2"
                label="Catatan"
                value={value.notes}
                onChange={(event) => onChange('notes', event.target.value)}
                error={errors.notes}
                rows={3}
                readOnly={readOnly}
            />
        </div>
    );
}
