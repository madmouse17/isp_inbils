import { useState } from 'react';
import { KeyRound } from 'lucide-react';
import { Button, Input } from '@/Components/ui';
import { CopyButton } from '@/Components/composite/CopyButton';
import { randomRadiusSecret } from '@/lib/routerosCommands';

interface RadiusSecretFieldProps {
    value: string;
    onChange: (value: string) => void;
    hint: string;
    error?: string;
    placeholder?: string;
    required?: boolean;
}

/**
 * The RADIUS shared secret of a router: a masked field, "Buat secret acak" for one nobody has to invent or type twice, and
 * "Salin secret" to carry it to the router. The secret lives in the form state only until the form is sent.
 */
export function RadiusSecretField({
    value,
    onChange,
    hint,
    error,
    placeholder,
    required,
}: RadiusSecretFieldProps) {
    const [generated, setGenerated] = useState(false);
    // The field is masked, so the page says that a secret was filled in; the same line is what a screen reader announces.
    const filled = generated && value !== '';

    return (
        <div className="space-y-2">
            <Input
                label="Secret RADIUS"
                type="password"
                autoComplete="off"
                placeholder={placeholder}
                value={value}
                onChange={(event) => {
                    setGenerated(false);
                    onChange(event.target.value);
                }}
                hint={hint}
                error={error}
                required={required}
            />
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    leftIcon={<KeyRound className="size-4" />}
                    onClick={() => {
                        setGenerated(true);
                        onChange(randomRadiusSecret());
                    }}
                >
                    Buat secret acak
                </Button>
                <CopyButton
                    value={value}
                    label="Salin secret"
                    copiedLabel="Disalin"
                    disabled={value === ''}
                />
            </div>
            <p role="status" className={filled ? 'text-sm text-muted-foreground' : 'sr-only'}>
                {filled ? 'Secret acak sudah diisi. Salin ke router sebelum menyimpan.' : ''}
            </p>
        </div>
    );
}
