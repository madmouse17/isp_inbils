/**
 * The bubble a browser shows for a native constraint (`required`, `type="email"`, `min`...) speaks the browser's
 * language, not the app's. One capture-phase listener on the document gives every field in every form the same
 * Indonesian text, so no field needs its own message.
 */

type Control = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;

/** The part of a form control the text depends on; a plain object satisfies it, which keeps the rules testable. */
export interface ValidityTarget {
    validity: Partial<Record<keyof ValidityState, boolean>>;
    min?: string;
    max?: string;
    minLength?: number;
    maxLength?: number;
}

/** The text for the first constraint the field breaks, or null when none of the known ones applies. */
export function validityMessage({ validity, min, max, minLength, maxLength }: ValidityTarget) {
    if (validity.valueMissing) return 'Wajib diisi.';
    if (validity.typeMismatch || validity.patternMismatch) return 'Format tidak sesuai.';
    if (validity.rangeOverflow) return `Nilai paling besar ${max}.`;
    if (validity.rangeUnderflow) return `Nilai paling kecil ${min}.`;
    if (validity.tooShort) return `Paling sedikit ${minLength} karakter.`;
    if (validity.tooLong) return `Paling banyak ${maxLength} karakter.`;
    if (validity.badInput || validity.stepMismatch) return 'Nilai tidak sesuai.';

    return null;
}

// A message set by this module is cleared when the person edits the field; one set by a page stays the page's.
const ours = new WeakSet<Control>();

function describe(event: Event) {
    const control = event.target as Control;

    // A custom error the page set itself (a server rule, a cross-field check) keeps its own text.
    if (control.validity.customError && !ours.has(control)) return;

    const message = validityMessage(control);

    if (message === null) {
        // The value was changed without an input event (a picker set it): the old text no longer applies.
        if (ours.delete(control)) control.setCustomValidity('');

        return;
    }

    control.setCustomValidity(message);
    ours.add(control);
}

// A custom error blocks the form until it is cleared, so it must go the moment the value changes.
function release(event: Event) {
    const control = event.target as Control;

    if (ours.delete(control)) control.setCustomValidity('');
}

let installed = false;

export function installNativeValidity() {
    if (installed || typeof document === 'undefined') return;
    installed = true;

    document.addEventListener('invalid', describe, true);
    document.addEventListener('input', release, true);
    document.addEventListener('change', release, true);
}
