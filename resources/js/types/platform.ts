import type { PaginationMeta, Paginated } from '@/types';

export type PlatformTenantStatus = 'active' | 'suspended' | 'deletion_requested';
export type TenantExportStatus = 'queued' | 'running' | 'ready' | 'failed' | 'expired';

export type PlatformAdminSummary = {
    id: number;
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
    id: number;
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
    id: number;
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

export type PlatformCompanyUser = {
    id: number;
    name: string;
    email: string;
    company: { id: number; name: string; code: string };
    roles: string[];
    is_active: boolean;
    last_login_at: string | null;
    two_factor_enabled: boolean;
    can_impersonate: boolean;
    invitation_pending: boolean;
};

export type PlatformSubsidiary = {
    id: number;
    name: string;
    code: string;
    is_active: boolean;
    currency: string;
    timezone: string;
    users_count: number;
};

export type PlatformAuditRow = {
    id: number;
    log_name: string;
    description: string;
    subject_type: string | null;
    causer: { type: 'platform_admin' | 'user'; name: string | null } | null;
    reason: string | null;
    created_at: string;
};

export type PlatformFailedJob = {
    id: number;
    uuid: string;
    queue: string;
    job: string | null;
    exception: string;
    failed_at: string;
    /** Only the 30-day support list names the company of each job; the 7-day rows of the detail page do not. */
    company?: { id: number; code: string };
};

export type PlatformFailedWhatsApp = {
    id: number;
    event: string;
    template: string | null;
    error_code: string | null;
    recipient_last4: string | null;
    company: { id: number; code: string };
    failed_at: string;
};

export type PlatformTenantExport = {
    id: number;
    uuid: string;
    status: TenantExportStatus;
    file_size: number | null;
    row_counts: Record<string, number> | null;
    error_message: string | null;
    requested_by: string | null;
    created_at: string | null;
    completed_at: string | null;
    expires_at: string | null;
};

/** A tenant as a filter lists it and an export row names it. */
export type PlatformTenantOption = { id: number; name: string; code: string };

/** A row of `Platform/Exports/Index`: the detail row plus its tenant and the signed link (null once it cannot be used). */
export type PlatformExportRow = PlatformTenantExport & {
    tenant: PlatformTenantOption;
    download_url: string | null;
};

export type PlatformExportFilters = {
    company_id: number | null;
    status: TenantExportStatus | null;
    page: number;
    per_page: number;
};

export type PlatformAuditEntry = {
    id: number;
    log_name: string;
    description: string;
    company: { id: number | null; name: string | null; code: string } | null;
    causer: { type: 'platform_admin' | 'user'; id: number; name: string | null } | null;
    subject: { type: string; id: number } | null;
    properties: Record<string, unknown>;
    batch_uuid: string | null;
    created_at: string;
};

export type PlatformAuditFilters = {
    company_id: number | null;
    platform_admin_id: number | null;
    event: string | null;
    date_from: string | null;
    date_to: string | null;
    search: string | null;
    page: number;
    per_page: number;
};

export type PlatformAdminOption = { id: number; name: string };

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

export type PlatformCompanyShowProps = {
    tenant: PlatformTenantProfile;
    metrics: PlatformTenantMetrics;
    admins: PlatformCompanyUser[];
    subsidiaries: PlatformSubsidiary[];
    users: Paginated<PlatformCompanyUser>;
    userFilters: PlatformUserFilters;
    recentAudit: PlatformAuditRow[];
    failedJobs: PlatformFailedJob[];
    failedWhatsApp: PlatformFailedWhatsApp[];
    exports: PlatformTenantExport[];
    lifecycle: PlatformLifecycle;
};

export type PlatformRole = {
    id: number;
    name: string;
    permissions: string[];
    users_count?: number;
};

export type PlatformRoleFilters = {
    search?: string;
    sort?: string;
    direction?: string;
    per_page?: number | string;
};

export type PlatformTwoFactorStatus = {
    enabled: boolean;
    pending: boolean;
    recovery_codes_remaining: number;
};

export type PlatformTwoFactorFlash = {
    setup?: { setup_key: string; qr_svg: string };
    recovery_codes?: string[];
};
