import type { ResetPeriod } from '@/lib/numberTemplate';

export interface NumberSequence {
    id: string;
    entity_type: string;
    label: string;
    prefix: string;
    template: string;
    next_number: number;
    reset_period: ResetPeriod;
    allow_manual: boolean;
    preview: string;
    max_length: number;
    updated_at: string | null;
}

export interface NumberSequenceToken {
    token: string;
    description: string;
}
