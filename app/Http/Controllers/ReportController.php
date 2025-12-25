<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    /**
     * Display the report dashboard.
     */
    public function index(): Response
    {
        // TODO: Implement report selection
        return Inertia::render('Reports/Index');
    }

    /**
     * Display monthly contribution summary.
     */
    public function monthly(Request $request): Response
    {
        // TODO: Implement with category breakdown
        $year = $request->get('year', now()->year);
        $month = $request->get('month', now()->month);

        return Inertia::render('Reports/Monthly', [
            'year' => $year,
            'month' => $month,
        ]);
    }

    /**
     * Display annual contribution summary.
     */
    public function annual(Request $request): Response
    {
        // TODO: Implement with month-by-month breakdown
        $year = $request->get('year', now()->year);

        return Inertia::render('Reports/Annual', [
            'year' => $year,
        ]);
    }
}
