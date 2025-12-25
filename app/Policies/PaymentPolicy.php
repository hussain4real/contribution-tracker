<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * Super Admins and Financial Secretaries can view all payments.
     * Members can only view their own payments.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * Super Admins and Financial Secretaries can view any payment.
     * Members can only view their own payments.
     */
    public function view(User $user, Payment $payment): bool
    {
        // Super Admin and Financial Secretary can view any payment
        if ($user->canViewAllMembers()) {
            return true;
        }

        // Members can only view their own payments
        return $user->id === $payment->contribution?->user_id;
    }

    /**
     * Determine whether the user can create models.
     *
     * Only Super Admin and Financial Secretary can record payments.
     */
    public function create(User $user): bool
    {
        return $user->canRecordPayments();
    }

    /**
     * Determine whether the user can update the model.
     *
     * Only Super Admin can modify payments.
     */
    public function update(User $user, Payment $payment): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     *
     * Only Super Admin can delete payments (within 24 hours).
     */
    public function delete(User $user, Payment $payment): bool
    {
        if (! $user->isSuperAdmin()) {
            return false;
        }

        // Allow deletion within 24 hours of recording
        return $payment->created_at->diffInHours(now()) <= 24;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Payment $payment): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Payment $payment): bool
    {
        return $user->isSuperAdmin();
    }
}
