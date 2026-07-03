export interface OrganizationUnit {
    id: number;
    parent_id: number | null;
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
    id: number;
    user_id: number;
    organization_id: number | null;
    vehicle_id: number | null;
    employee_number: string;
    phone?: string | null;
    hire_date?: string | null;
    status: string;
    skills?: string[] | null;
    notes?: string | null;
    user?: { id: number; name: string; email: string } | null;
    organization?: { id: number; name: string; code: string } | null;
    vehicle?: { id: number; plate_number: string } | null;
}

export interface Vehicle {
    id: number;
    plate_number: string;
    type?: string | null;
    brand?: string | null;
    model?: string | null;
    purchase_date?: string | null;
    is_active: boolean;
    notes?: string | null;
}
