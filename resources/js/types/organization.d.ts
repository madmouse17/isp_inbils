export interface OrganizationUnit {
    id: string;
    parent_id: string | null;
    code: string;
    name: string;
    type: string;
    path?: string | null;
    address?: string | null;
    phone?: string | null;
    email?: string | null;
    is_active: boolean;
    children_count?: number;
}

export interface EmployeeProfile {
    id: string;
    user_id: string;
    organization_id: string | null;
    vehicle_id: string | null;
    employee_number: string;
    phone?: string | null;
    hire_date?: string | null;
    status: string;
    skills?: string[] | null;
    notes?: string | null;
    user?: { id: string; name: string; email: string } | null;
    organization?: { id: string; name: string; code: string } | null;
    vehicle?: { id: string; plate_number: string } | null;
}

export interface Vehicle {
    id: string;
    plate_number: string;
    type?: string | null;
    brand?: string | null;
    model?: string | null;
    purchase_date?: string | null;
    is_active: boolean;
    notes?: string | null;
}
