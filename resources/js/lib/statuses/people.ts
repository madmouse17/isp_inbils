import type { StatusMap } from './types';

export const EMPLOYEE_STATUS = {
    active: { label: 'Aktif', tone: 'success' },
    inactive: { label: 'Nonaktif', tone: 'neutral' },
    terminated: { label: 'Berhenti', tone: 'neutral' },
} satisfies StatusMap;

export const ORGANIZATION_TYPE = {
    company: { label: 'Perusahaan', tone: 'neutral' },
    branch: { label: 'Cabang', tone: 'neutral' },
    area: { label: 'Area', tone: 'neutral' },
    unit: { label: 'Unit', tone: 'neutral' },
    team: { label: 'Tim', tone: 'neutral' },
} satisfies StatusMap;
