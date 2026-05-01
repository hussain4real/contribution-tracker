<?php

namespace App\Http\Controllers;

use App\Services\GitHubReleaseService;
use Inertia\Inertia;
use Inertia\Response;

class ChangelogController extends Controller
{
    public function __invoke(GitHubReleaseService $releases): Response
    {
        return Inertia::render('Changelog/Index', [
            'releases' => $releases->releases(),
        ]);
    }
}
