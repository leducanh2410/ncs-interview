import { User } from '@/types/models/user';

export interface Salary {
    id: number;
    user_id: number;
    month: string;
    total_amount: number;
    total_deductions: number;
    total_fee: number;
    total_paid: number;
    total_remaining: number;
    min_salary: number;
    max_salary: number;
    total_minutes: number;
    is_completed: number;
    is_finalized: number;
    is_fully_paid: number;

    user: User;
}

export interface SalaryWithHours extends Salary {
    work_days: number;
    work_minutes: number;
    work_minutes_normal: number;
    work_minutes_night: number;
    work_minutes_overtime: number;
    work_minutes_night_overtime: number;
}

export interface SalaryRequest {
    id: number;
    user_id: number;
    date: string;
    status: string;
    type: string;
    max_amount: number;
    amount: number;
    user: User;
    approver_id: number | null;
    approved_at: string | null;
    accountant_approver_id: number | null;
    accountant_approved_at: string | null;
    rejected_at: string | null;
    reject_reason: string | null;
    created_at: string;
    paid_at: string | null;
    received_at: string | null;
    returned_at: string | null;
}

export interface SalaryRequestDetail {
    id: number;
    salary_request_id: number;
    salary_id: number;
    amount: number;
    salary: Salary;
}

export interface SalaryRequestWithRelation extends SalaryRequest {
    approver: User | null;
    accountant_approver: User | null;
    details: SalaryRequestDetail[];
}

export interface SalaryProcess {
    id: number;
    month: string;
    workplace_id: number;
    status: string;
    priority: number;
    scheduled_at: string;
    executed_at: string | null;
    workplace: Workplace;
}
