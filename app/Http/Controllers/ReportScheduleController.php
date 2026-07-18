<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReportFormat;
use App\Enums\ReportScheduleFrequency;
use App\Enums\ReportType;
use App\Http\Requests\StoreReportScheduleRequest;
use App\Models\Family;
use App\Models\ReportSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReportScheduleController extends Controller
{
    public function store(StoreReportScheduleRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $family = $user->currentFamily ?? $user->family;
        abort_unless($family instanceof Family, 403);

        $validated = $request->validated();
        $family->reportSchedules()->create([
            'created_by' => $user->id,
            'name' => is_string($validated['name']) ? $validated['name'] : '',
            'report_type' => ReportType::from(is_string($validated['report_type']) ? $validated['report_type'] : ''),
            'format' => ReportFormat::from(is_string($validated['format']) ? $validated['format'] : ''),
            'filters' => is_array($validated['filters']) ? $validated['filters'] : [],
            'channels' => is_array($validated['channels']) ? array_values(array_filter($validated['channels'], 'is_string')) : [],
            'recipients' => is_array($validated['recipients']) ? array_values(array_filter($validated['recipients'], 'is_string')) : [],
            'frequency' => ReportScheduleFrequency::from(is_string($validated['frequency']) ? $validated['frequency'] : ''),
            'timezone' => is_string($validated['timezone']) ? $validated['timezone'] : 'UTC',
            'next_run_at' => is_string($validated['next_run_at']) ? $validated['next_run_at'] : now(),
            'is_active' => true,
        ]);

        return back()->with('success', 'Report schedule created.');
    }

    public function destroy(Request $request, ReportSchedule $reportSchedule): RedirectResponse
    {
        $this->authorize('delete', $reportSchedule);
        $reportSchedule->delete();

        return back()->with('success', 'Report schedule removed.');
    }
}
