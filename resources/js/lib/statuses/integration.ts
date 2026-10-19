import type { StatusMap } from './types';

/** Where a tenant's service account stands (`CompanyIntegration::stateOf`): empty, filled in, checked OK, or last check failed. */
export const INTEGRATION_STATE = {
    empty: { label: 'Tidak diisi', tone: 'neutral' },
    filled: { label: 'Terisi', tone: 'info' },
    verified: { label: 'Terverifikasi', tone: 'success' },
    failed: { label: 'Galat', tone: 'danger' },
} satisfies StatusMap;

/** The external services a company connects with its own account (`company_integrations.provider`): kinds, so neutral. */
export const INTEGRATION_PROVIDER = {
    midtrans: { label: 'Midtrans', tone: 'neutral' },
    whatsapp: { label: 'WhatsApp', tone: 'neutral' },
    mail: { label: 'Email', tone: 'neutral' },
    radius: { label: 'RADIUS', tone: 'neutral' },
} satisfies StatusMap;
