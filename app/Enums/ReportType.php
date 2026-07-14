<?php

declare(strict_types=1);

namespace App\Enums;

enum ReportType: string
{
    case ContributionRegister = 'contribution_register';
    case ContributionAging = 'contribution_aging';
    case MemberStatement = 'member_statement';
    case CategoryPerformance = 'category_performance';
    case FundStatement = 'fund_statement';
    case CashFlow = 'cash_flow';
    case ExpenseTotals = 'expense_totals';
    case Reversals = 'reversals';
    case AuditActivity = 'audit_activity';
    case Receipt = 'receipt';

    public function label(): string
    {
        return match ($this) {
            self::ContributionRegister => 'Contribution Register',
            self::ContributionAging => 'Contribution Aging',
            self::MemberStatement => 'Member Statement',
            self::CategoryPerformance => 'Category Performance',
            self::FundStatement => 'Fund Statement',
            self::CashFlow => 'Cash Flow',
            self::ExpenseTotals => 'Expense & Category Totals',
            self::Reversals => 'Reversals',
            self::AuditActivity => 'Audit Activity',
            self::Receipt => 'Payment Receipt',
        };
    }
}
