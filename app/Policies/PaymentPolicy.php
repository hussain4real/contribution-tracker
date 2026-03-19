<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Payment $payment): bool
    {
        if ($user->family_id !== $payment->contribution?->family_id) {
            return false;
        }

        if ($user->canViewAllMembers()) {
            return true;
        }

        return $user->id === $payment->contribution?->user_id;
    }

    public function create(User $user): bool
    {
        return $user->canRecordPayments();
    }

    public function update(User $user, Payment $payment): bool
    {
        return $user->isAdmin() && $user->family_id === $payment->contribution?->family_id;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $user->isAdmin()
            && $user->family_id === $payment->contribution?->family_id
            && $payment->created_at->diffInHours(now()) <= 24;
    }

    public function restore(User $user, Payment $payment): bool
    {
        return $user->isAdmin() && $user->family_id === $payment->contribution?->family_id;
    }

    public function forceDelete(User $user, Payment $payment): bool
    {
        return $user->isAdmin() && $user->family_id === $payment->contribution?->family_id;
    }
}
