<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Family;
use App\Services\FamilyOnboarding;
use Inertia\Inertia;
use Inertia\Response;

class FamilyOnboardingController extends Controller
{
    public function index(FamilyOnboarding $onboarding): Response
    {
        $user = $this->authUser();
        $family = $user->currentFamily ?? $user->family;
        abort_unless($user->isAdmin() && $family instanceof Family, 403);

        return Inertia::render('Family/Onboarding', ['guide' => $onboarding->forFamily($family)]);
    }
}
