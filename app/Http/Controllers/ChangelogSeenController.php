<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\GitHubReleaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ChangelogSeenController extends Controller
{
    public function __invoke(Request $request, GitHubReleaseService $releases): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $releases->markLatestSeen($user);

        return back();
    }
}
