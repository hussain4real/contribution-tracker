<?php

use App\Http\Controllers\ContributionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FundAdjustmentController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReportController;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('/privacy', fn () => Inertia::render('Legal/Privacy'))->name('privacy');
Route::get('/terms', fn () => Inertia::render('Legal/Terms'))->name('terms');
Route::get('/data-deletion', fn () => Inertia::render('Legal/DataDeletion'))->name('data-deletion');

// =========================================================================
// Authenticated Routes
// =========================================================================

Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Members (Super Admin only for management, all can view list)
    Route::resource('members', MemberController::class);
    Route::post('members/{member}/restore', [MemberController::class, 'restore'])->name('members.restore');

    // Contributions
    Route::get('contributions', [ContributionController::class, 'index'])->name('contributions.index');
    Route::get('contributions/my', [ContributionController::class, 'my'])->name('contributions.my');
    Route::get('contributions/{contribution}', [ContributionController::class, 'show'])->name('contributions.show');

    // Payments
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('members/{member}/payments/create', [PaymentController::class, 'create'])->name('payments.create');
    Route::post('payments', [PaymentController::class, 'store'])->name('payments.store')
        ->middleware([HandlePrecognitiveRequests::class]);
    Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

    // Expenses
    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
    Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store')
        ->middleware([HandlePrecognitiveRequests::class]);
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

    // Fund Adjustments (Super Admin only for create/delete)
    Route::get('fund-adjustments', [FundAdjustmentController::class, 'index'])->name('fund-adjustments.index');
    Route::post('fund-adjustments', [FundAdjustmentController::class, 'store'])->name('fund-adjustments.store')
        ->middleware([HandlePrecognitiveRequests::class]);
    Route::delete('fund-adjustments/{fund_adjustment}', [FundAdjustmentController::class, 'destroy'])->name('fund-adjustments.destroy');

    // Reports (Financial Secretary and Super Admin only)
    Route::prefix('reports')->name('reports.')->middleware('can:generate-reports')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('monthly', [ReportController::class, 'monthly'])->name('monthly');
        Route::get('annual', [ReportController::class, 'annual'])->name('annual');
    });
});

require __DIR__.'/settings.php';
