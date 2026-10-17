/** WCAG 2.x contrast of white text on a brand colour; the AA floor for normal text is 4.5:1. */
export const MIN_TEXT_CONTRAST = 4.5;

const HEX_COLOR = /^#[0-9A-Fa-f]{6}$/;

export function isHexColor(value: string): boolean {
    return HEX_COLOR.test(value);
}

function channel(hex: string, start: number): number {
    const value = parseInt(hex.slice(start, start + 2), 16) / 255;
    return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
}

/** Contrast ratio between white and `#RRGGBB`; `null` when the value is not a full hex colour. */
export function contrastWithWhite(hex: string): number | null {
    if (!isHexColor(hex)) return null;
    const luminance =
        0.2126 * channel(hex, 1) + 0.7152 * channel(hex, 3) + 0.0722 * channel(hex, 5);
    return 1.05 / (luminance + 0.05);
}
