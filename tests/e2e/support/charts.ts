import type { Locator, Page } from '@playwright/test';

import { expect } from './fixtures';

/** What a chart card shows when its period holds nothing. */
export const EMPTY_CHART_TEXT = 'Tidak ada data pada periode ini';

/** A report chart card, found by the heading that names it. */
export function chartCard(page: Page, title: string | RegExp): Locator {
    return page.getByRole('group', { name: title, exact: true });
}

/** The drawn chart of a card: the `svg` carrying the chart's `aria-label`. */
export function chartSvg(card: Locator): Locator {
    return card.locator('svg[aria-label]');
}

/** The lazy chart chunk has loaded: the card shows a drawn chart or the empty message. */
export async function expectChartSettled(card: Locator): Promise<void> {
    await expect(chartSvg(card).or(card.getByText(EMPTY_CHART_TEXT))).toBeVisible();
}
