import type { SearchSelectOption } from '@/Components/ui/SearchSelect';

/** `packageGroups` prop of the package pickers: active packages grouped by their category. */
export interface PackageGroup {
    category: { id: number; code: string; name: string };
    packages: { value: string; label: string; description: string }[];
}

/** Category codes listed first for a customer type; the server uses the same rule for `?customer_type=`. */
const PREFERRED_CODES: Record<string, string[]> = {
    Individual: ['RUMAHAN'],
    Company: ['BISNIS', 'KORPORAT'],
};

/**
 * Options for a package `SearchSelect`, headed by category name. With `customerType`, the categories
 * that suit it come first (the rest keep the server's order); without it the order is the server's.
 */
export function packageGroupOptions(
    groups: PackageGroup[],
    customerType?: string,
): SearchSelectOption[] {
    const preferred = (customerType && PREFERRED_CODES[customerType]) || [];
    const rank = (group: PackageGroup) => {
        const index = preferred.indexOf(group.category.code);

        return index === -1 ? preferred.length : index;
    };

    return [...groups]
        .sort((a, b) => rank(a) - rank(b))
        .flatMap((group) =>
            group.packages.map((item) => ({
                value: item.value,
                label: item.label,
                description: item.description,
                group: group.category.name,
            })),
        );
}
