<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case FinancialSecretary = 'financial_secretary';
    case Member = 'member';

    /**
     * Get the human-readable label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::FinancialSecretary => 'Financial Secretary',
            self::Member => 'Member',
        };
    }

    /**
     * Check if this role can record payments.
     */
    public function canRecordPayments(): bool
    {
        return $this !== self::Member;
    }

    /**
     * Check if this role can manage members.
     */
    public function canManageMembers(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Check if this role can manage roles.
     */
    public function canManageRoles(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Check if this role can view all member details.
     */
    public function canViewAllMembers(): bool
    {
        return $this !== self::Member;
    }

    /**
     * Check if this role can generate reports.
     */
    public function canGenerateReports(): bool
    {
        return $this !== self::Member;
    }
}
