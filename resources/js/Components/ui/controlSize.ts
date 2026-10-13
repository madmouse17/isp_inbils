/**
 * Control heights. Touch screens (narrower than `sm`, or any coarse pointer) get 44px targets; a
 * mouse-driven desktop gets the compact size.
 */
export const controlHeight = {
    sm: 'h-11 sm:h-8 sm:pointer-coarse:h-11',
    md: 'h-11 sm:h-9 sm:pointer-coarse:h-11',
    lg: 'h-11 sm:h-10 sm:pointer-coarse:h-11',
} as const;

export const controlSquare =
    'h-11 w-11 sm:h-9 sm:w-9 sm:pointer-coarse:h-11 sm:pointer-coarse:w-11';
