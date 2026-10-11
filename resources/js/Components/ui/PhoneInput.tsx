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
            // No `pattern` attribute: Chromium compiles it with the Unicode `v`
            // flag, under which unescaped `(` `)` in a character class are a
            // syntax error, and the HTML parser strips backslash escapes — so
            // any literal parentheses class is impossible to express. The
            // onChange sanitizer + server validation enforce the format instead.
            onChange={(event) => onChange(event.target.value.replace(/[^0-9()+-]/g, ''))}
        />
    ),
);

PhoneInput.displayName = 'PhoneInput';
