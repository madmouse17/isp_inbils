export const ALL_FILTER_VALUE = '__all__';

export function toFilterValue(value: string | null | undefined): string {
    return value ? value : ALL_FILTER_VALUE;
}

export function fromFilterValue(value: string): string {
    return value === ALL_FILTER_VALUE ? '' : value;
}
