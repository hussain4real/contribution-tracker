<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Models\Family;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait AuthorizesFamilyFundReview
{
    private function authorizedUser(Request $request): User|Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('Authentication is required.');
        }

        if (! $user->hasVerifiedEmail()) {
            return Response::error('Your email address is not verified.');
        }

        if (! $user->canRecordPayments()) {
            return Response::error('Permission denied. Admin or Financial Secretary access is required.');
        }

        $family = $user->currentFamily ?? $user->family;

        if (! $family instanceof Family || ! $user->belongsToFamily($family)) {
            return Response::error('A family workspace is required.');
        }

        return $user;
    }
}
