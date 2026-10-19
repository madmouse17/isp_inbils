export type IntegrationProvider = 'midtrans' | 'whatsapp' | 'mail' | 'radius';
export type IntegrationState = 'empty' | 'filled' | 'verified' | 'failed';

export interface IntegrationCard {
    provider: IntegrationProvider;
    state: IntegrationState;
    is_enabled: boolean;
    /** A queued "Uji koneksi" is still running (`Cache::has(integrations:checking:{company}:{provider})`). */
    checking: boolean;
    /** ISO 8601 */
    verified_at: string | null;
    last_checked_at: string | null;
    last_error: string | null;
    /** `publicSettings()`: midtrans {is_production}; whatsapp {phone_number_id}; mail {host, port, scheme, username, from_address, from_name}; radius {host, port, database, username}. */
    settings: Record<string, string | number | boolean | null>;
    /** `masked()`: every secret key, "tersimpan (••••1234)", "tersimpan" or null. The value itself never reaches the page. */
    secrets: Record<string, string | null>;
}

export interface IntegrationsPageProps {
    integrations: Record<IntegrationProvider, IntegrationCard>;
    webhooks: { midtrans: string; whatsapp: string };
    firebase: { configured: boolean; last_sent_at: string | null };
    mailFallback: { from_address: string | null };
    twoFactorEnabled: boolean;
}
