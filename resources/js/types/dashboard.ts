export type PaymentStatus = 'paid' | 'partial' | 'unpaid' | 'overdue';

export interface MonthlyBreakdown {
    period: string;
    year: number;
    month: number;
    expected: number;
    collected: number;
}
