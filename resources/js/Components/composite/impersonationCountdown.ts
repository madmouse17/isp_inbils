/** Whole seconds left until `expiresAt` (ISO 8601), never negative. */
export function secondsLeft(expiresAt: string, now: number): number {
    return Math.max(0, Math.ceil((Date.parse(expiresAt) - now) / 1000));
}

/** mm:ss, minutes unbounded. */
export function formatCountdown(seconds: number): string {
    const minutes = Math.floor(seconds / 60);

    return `${String(minutes).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
}
