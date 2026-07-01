export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    company_id?: number | null;
    is_active?: boolean;
    roles?: string[];
    permissions?: string[];
}

export interface Company {
    id: number;
    name: string;
    code: string;
    logo?: string | null;
    address?: string | null;
    phone?: string | null;
    email?: string | null;
    website?: string | null;
    currency: string;
    timezone: string;
    settings?: Record<string, unknown>;
    is_active?: boolean;
}

export interface Flash {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User | null;
    };
    company: Company | null;
    flash: Flash;
    app: {
        name: string;
        locale: string;
    };
};
