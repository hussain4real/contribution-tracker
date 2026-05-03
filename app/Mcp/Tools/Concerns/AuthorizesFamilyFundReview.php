<?php

namespace App\Mcp\Tools\Concerns;

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

        if (! $user->canRecordPayments()) {
            return Response::error('Permission denied. Admin or Financial Secretary access is required.');
        }

        if ($user->family_id === null) {
            return Response::error('A family workspace is required.');
        }

        return $user;
    }
}
