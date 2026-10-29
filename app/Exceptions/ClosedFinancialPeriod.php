<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ClosedFinancialPeriod extends InvalidArgumentException implements ShouldntReport
{
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage(), 'errors' => ['financial_period' => [$this->getMessage()]]], 422);
        }

        return back()->with('error', $this->getMessage())->withInput();
    }
}
