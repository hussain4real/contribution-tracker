<?php

use App\Http\Controllers\ContributionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

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
    Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

    // Reports (Financial Secretary and Super Admin only)
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('monthly', [ReportController::class, 'monthly'])->name('monthly');
        Route::get('annual', [ReportController::class, 'annual'])->name('annual');
    });
});

require __DIR__.'/settings.php';
