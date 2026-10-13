/**
 * What a badge says about a state. Only a state gets a colour: success is done or healthy, info is
 * under way, warning is waiting or needs a look, danger is failed, late or refused, neutral is
 * ended, inactive or just a kind (a type, a source) that has no state to report.
 */
export type BadgeTone = 'success' | 'info' | 'warning' | 'danger' | 'neutral';

export interface StatusEntry {
    label: string;
    tone: BadgeTone;
}

/** One domain value set: the stored value (`on_progress`) to what the screen says (`Dikerjakan`) and in which tone. */
export type StatusMap = Readonly<Record<string, StatusEntry>>;

/** What a page has at hand: a stored string, a number (`family` 4 or 6) or an `is_active` boolean. */
export type StatusValue = string | number | boolean | null | undefined;

export interface ResolvedStatus extends StatusEntry {
    /** False for a value the map does not list, shown raw in the neutral tone instead of failing. */
    known: boolean;
}

/** The label and tone of a value; null for no value at all (null, undefined or an empty string). */
export function resolveStatus(map: StatusMap, value: StatusValue): ResolvedStatus | null {
    if (value === null || value === undefined || value === '') return null;

    const key = String(value);
    // hasOwn: a stored `constructor` or `__proto__` must not read the object's own members.
    const entry = Object.hasOwn(map, key) ? map[key] : undefined;

    return entry ? { ...entry, known: true } : { label: key, tone: 'neutral', known: false };
}

/** The label as plain text (CSV, chart legend, dialog), `-` for no value. An unknown value is itself. */
export function labelOf(map: StatusMap, value: StatusValue): string {
    return resolveStatus(map, value)?.label ?? '-';
}

/** The options of a filter or a form select, in the order the map lists them. */
export function optionsOf(map: StatusMap): Array<{ value: string; label: string }> {
    return Object.entries(map).map(([value, { label }]) => ({ value, label }));
}

/** The CSS colour of a tone for a chart or a legend dot (`--status-*` in app.css, light and dark). */
export function toneColor(tone: BadgeTone): string {
    return `var(--status-${tone})`;
}
