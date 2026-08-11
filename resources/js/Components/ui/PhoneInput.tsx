import React from 'react';
import { Input, type InputProps } from './Input';

export interface PhoneInputProps extends Omit<
    InputProps,
    'inputMode' | 'onChange' | 'pattern' | 'type'
> {
    value: string;
    onChange: (value: string) => void;
}

export const PhoneInput = React.forwardRef<HTMLInputElement, PhoneInputProps>(
    ({ onChange, ...props }, ref) => (
        <Input
            {...props}
            ref={ref}
            type="tel"
            inputMode="tel"
            pattern="[0-9()+-]*"
            onChange={(event) => onChange(event.target.value.replace(/[^0-9()+-]/g, ''))}
        />
    ),
);

PhoneInput.displayName = 'PhoneInput';
