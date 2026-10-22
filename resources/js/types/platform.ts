import type { PaginationMeta, Paginated } from '@/types';
import type { IntegrationProvider, IntegrationState } from '@/types/integrations';

export type PlatformTenantStatus = 'active' | 'suspended' | 'deletion_requested';
export type TenantExportStatus = 'queued' | 'running' | 'ready' | 'failed' | 'expired';

export type PlatformAdminSummary = {
    id: string;
    name: string;
    email: string;
    two_factor_enabled: boolean;
};

export type PlatformSharedProps = Record<string, unknown> & {
    platform: { admin: PlatformAdminSummary | null };
    flash: { success?: string | null; error?: string | null; warning?: string | null };
    app: { name: string; locale: string };
};

export type PlatformTenantMetrics = {
    subsidiaries_count: number;
    users_count: number;
    customers_count: number;
    last_login_at: string | null;
    storage_bytes: number;
    failed_jobs_7d: number;
    failed_whatsapp_7d: number;
};

export type PlatformTenantRow = PlatformTenantMetrics & {
    id: string;
    name: string;
    code: string;
    status: PlatformTenantStatus;
};

export type PlatformTenantSummary = {
    active: number;
    suspended: number;
    deletion_requested: number;
    unattributed_failed_jobs_7d: number;
};

export type PlatformTenantFilters = {
    search: string | null;
    status: PlatformTenantStatus | null;
    sort: 'name' | 'code' | 'created_at';
    direction: 'asc' | 'desc';
    per_page: number;
};

export type PlatformTenantProfile = {
    id: string;
    name: string;
    code: string;
    status: PlatformTenantStatus;
    email: string | null;
    phone: string | null;
    website: string | null;
    address: string | null;
    timezone: string;
    currency: string;
    suspended_at: string | null;
    deletion_requested_at: string | null;
    created_at: string | null;
};

/** What keeps a person from signing in right now: too many wrong passwords, or too many wrong second-factor codes. */
export type PlatformLoginLock = { reason: 'password' | 'two_factor'; seconds: number };

export type PlatformInvitationState = 'queued' | 'sent' | 'expired' | 'failed';

export type PlatformCompanyUser = {
    id: string;
    name: string;
    email: string;
    company: { id: string; name: string; code: string };
    roles: string[];
    is_active: boolean;
    last_login_at: string | null;
    two_factor_enabled: boolean;
    can_impersonate: boolean;
    login_lock: PlatformLoginLock | null;
    /** Where the set-password mail stands; only for people who never signed in. */
    invitation: { state: PlatformInvitationState; at: string } | null;
    can_resend_invitation: boolean;
};

export type PlatformSubsidiary = {
    id: string;
    name: string;
    code: string;
    is_active: boolean;
    currency: string;
    timezone: string;
    users_count: number;
};

/** A tenant's open request for a subsidiary (K34): what the admin typed, who asked, and the tenant it came from. */
export type PlatformSubsidiaryRequest = {
    id: string;
    name: string;
    code: string | null;
    note: string | null;
    requested_at: string | null;
    requester: { name: string } | null;
    company: { id: string; name: string; code: string };
};

export type PlatformAuditRow = {
    id: string;
    log_name: string;
    description: string;
    /** The sentence of the row, worded by the server (AuditEventText). */
    text: string;
    subject_type: string | null;
    causer: { type: 'platform_admin' | 'user'; name: string | null } | null;
    reason: string | null;
    created_at: string;
};

export type PlatformFailedJob = {
    uuid: string;
    queue: string;
    job: string | null;
    exception: string;
    failed_at: string;
    /** Only the 30-day support list names the company of each job; the 7-day rows of the detail page do not. */
    company?: { id: string; code: string };
};

export type PlatformFailedWhatsApp = {
    id: string;
    event: string;
    template: string | null;
    error_code: string | null;
    recipient_last4: string | null;
    company: { id: string; code: string };
    failed_at: string;
};

export interface PlatformTenantIntegration {
    company: { id: string; code: string; name: string };
    provider: IntegrationProvider;
    state: IntegrationState;
    is_enabled: boolean;
    /** ISO 8601 */
    last_checked_at: string | null;
}

export type PlatformTenantExport = {
    id: string;
    uuid: string;
    status: TenantExportStatus;
    file_size: number | null;
    row_counts: Record<string, number> | null;
    error_message: string | null;
    requested_by: string | null;
    created_at: string | null;
    completed_at: string | null;
    expires_at: string | null;
    /** Signed link while the file can still be downloaded, else null. */
    download_url: string | null;
};

/** A tenant as a filter lists it and an export row names it. */
export type PlatformTenantOption = { id: string; name: string; code: string };

/** A row of `Platform/Exports/Index`: the detail row plus its tenant and the signed link (null once it cannot be used). */
export type PlatformExportRow = PlatformTenantExport & {
    tenant: PlatformTenantOption;
    download_url: string | null;
};

export type PlatformExportFilters = {
    company_id: string | null;
    status: TenantExportStatus | null;
    page: number;
    per_page: number;
};

export type PlatformAuditEntry = {
    id: string;
    log_name: string;
    description: string;
    /** The sentence of the row, worded by the server (AuditEventText). */
    text: string;
    company: { id: string | null; name: string | null; code: string } | null;
    causer: { type: 'platform_admin' | 'user'; id: string; name: string | null } | null;
    subject: { type: string; id: string } | null;
    /** What the row is about, as a person names it; null when it has nothing to name. */
    subject_label: string | null;
    properties: Record<string, unknown>;
    batch_uuid: string | null;
    created_at: string;
};

export type PlatformAuditFilters = {
    company_id: string | null;
    platform_admin_id: string | null;
    event: string | null;
    source: PlatformAuditSource;
    date_from: string | null;
    date_to: string | null;
    search: string | null;
    page: number;
    per_page: number;
};

/** `platform` is the operator's own log, one row per action; `support` is the tenant's copy; `all` lists both. */
export type PlatformAuditSource = 'platform' | 'support' | 'all';

/** An action the audit page can filter by, with its sentence. */
export type PlatformAuditEventOption = { value: string; label: string };

/** What was wrong with each refused filter of the audit page, by filter name. */
export type PlatformAuditFilterErrors = Partial<Record<keyof PlatformAuditFilters, string>>;

export type PlatformAdminOption = { id: string; name: string };

/** One page of a failed-jobs or failed-notifications list, as the support endpoints answer in JSON. */
export type PlatformFailedPage<T> = {
    data: T[];
    meta: PaginationMeta;
    window_days: number;
};

export type PlatformLifecycle = {
    can_suspend: boolean;
    can_reactivate: boolean;
    can_request_deletion: boolean;
    deletion_blockers: string[];
};

export type PlatformUserFilters = {
    search: string | null;
    status: 'active' | 'inactive' | null;
    per_page: number;
};

/** One company's roles on the tenant page: the tenant root first, then each subsidiary. */
export type PlatformCompanyRoleRow = {
    id: string;
    name: string;
    template_key: string | null;
    permissions_count: number;
    users_count: number;
};

export type PlatformCompanyRoles = {
    company: PlatformTenantOption;
    roles: PlatformCompanyRoleRow[];
};

export type PlatformRoleTemplateOption = { key: string; name: string };

export type PlatformSuspendImpact = {
    /** People of the tenant who can still sign in. */
    users: number;
    /** Live sessions of those people; null when the session driver cannot be counted. */
    sessions: number | null;
    app_tokens: number;
    open_invoices: { count: number; outstanding: string };
};

export type PlatformSessionDriver = {
    /** Suspending ends every session at once (the database driver). */
    ends_all: boolean;
    lifetime_minutes: number;
};

export type PlatformCompanyShowProps = {
    tenant: PlatformTenantProfile;
    metrics: PlatformTenantMetrics;
    admins: PlatformCompanyUser[];
    subsidiaries: PlatformSubsidiary[];
    /** The tenant's open requests for a subsidiary, oldest first. */
    subsidiaryRequests: PlatformSubsidiaryRequest[];
    users: Paginated<PlatformCompanyUser>;
    userFilters: PlatformUserFilters;
    companyRoles: PlatformCompanyRoles[];
    roleTemplates: PlatformRoleTemplateOption[];
    recentAudit: PlatformAuditRow[];
    failedJobs: PlatformFailedJob[];
    failedWhatsApp: PlatformFailedWhatsApp[];
    integrations: PlatformTenantIntegration[];
    exports: PlatformTenantExport[];
    lifecycle: PlatformLifecycle;
    /** What suspending would cut off; null once the tenant is not active. */
    suspendImpact: PlatformSuspendImpact | null;
    sessionDriver: PlatformSessionDriver;
};

/** A row of `Platform/RoleTemplates/Index`. The admin and customer templates are not deletable. */
export type PlatformRoleTemplateRow = {
    id: string;
    key: string;
    name: string;
    permissions_count: number;
    deletable: boolean;
};

export type PlatformRoleTemplate = {
    id: string;
    key: string;
    name: string;
    permissions: string[];
    deletable: boolean;
};

/** A role of one company, as the repair form edits it. `version` goes back as `expected_updated_at`. */
export type PlatformCompanyRole = {
    id: string;
    name: string;
    template_key: string | null;
    permissions: string[];
    users_count: number;
    version: string;
};

/** The template a new company role starts from (`?template=<key>`). */
export type PlatformRoleTemplateSeed = { key: string; name: string; permissions: string[] };

export type PlatformTwoFactorStatus = {
    enabled: boolean;
    pending: boolean;
    recovery_codes_remaining: number;
};

export type PlatformTwoFactorFlash = {
    setup?: { setup_key: string; qr_svg: string };
    recovery_codes?: string[];
};
