export interface Customer {
    id: number;
    code: string;
    name: string;
    type: 'Individual' | 'Company';
    email?: string | null;
    phone?: string | null;
    tax_id?: string | null;
    contact_person?: string | null;
    area_coverage_id?: number | null;
    notes?: string | null;
    is_active: boolean;
    addresses_count?: number;
    subscriptions_count?: number;
    created_at?: string;
    addresses?: CustomerAddress[];
    contacts?: CustomerContact[];
    subscriptions?: ServiceSubscription[];
}

export interface CustomerAddress {
    id: number;
    customer_id: number;
    label: string;
    address: string;
    city?: string | null;
    postal_code?: string | null;
    lat?: string | null;
    lng?: string | null;
    is_installation_point: boolean;
    is_primary: boolean;
    notes?: string | null;
}

export interface CustomerContact {
    id: number;
    customer_id: number;
    name: string;
    position?: string | null;
    phone?: string | null;
    email?: string | null;
    is_primary: boolean;
    notes?: string | null;
}

export interface ServiceSubscription {
    id: number;
    customer_id: number;
    service_package_id: number;
    installation_address_id: number;
    code: string;
    status: 'pending' | 'active' | 'suspended' | 'terminated';
    activation_date?: string | null;
    expiration_date?: string | null;
    billing_day: number;
    next_invoice_date?: string | null;
    ont_asset_id?: number | null;
    serving_pop_id?: number | null;
    mrc_amount: string;
    otc_installation_fee: string;
    contract_months?: number | null;
    notes?: string | null;
    terminated_at?: string | null;
    terminated_reason?: string | null;
    created_at?: string;
    package?: { id: number; code: string; name: string; price_mrc: string } | null;
    customer?: Customer | null;
    installation_address?: CustomerAddress | null;
    serving_pop?: { id: number; code: string; name: string } | null;
}
