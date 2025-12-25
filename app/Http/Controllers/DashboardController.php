<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the contribution dashboard.
     */
    public function index(): Response
    {
        // TODO: Implement role-conditional props with deferred loading
        return Inertia::render('Dashboard/Index');
    }
}
