<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\PlatformStatsOverview;
use App\Filament\Widgets\RecentFamilies;
use App\Filament\Widgets\RecentPayments;
use App\Http\Middleware\EnsurePlatformSuperAdmin;
use App\Models\FamilyMembership;
use App\Models\User;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('platform')
            ->path('platform')
            ->brandName('FamilyFund Platform')
            ->navigationItems([
                NavigationItem::make('Family dashboard')
                    ->icon(Heroicon::OutlinedHome)
                    ->url(fn (): ?string => $this->familyDashboardUrl())
                    ->visible(fn (): bool => $this->familyDashboardUrl() !== null)
                    ->sort(-1),
            ])
            ->login(fn () => redirect()->route('login'))
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                PlatformStatsOverview::class,
                RecentFamilies::class,
                RecentPayments::class,
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureEmailIsVerified::class,
                EnsurePlatformSuperAdmin::class,
            ], isPersistent: true);
    }

    private function familyDashboardUrl(): ?string
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $membership = $user->familyMemberships()->active()
            ->whereHas('family', fn (Builder $query): Builder => $query->whereNull('archived_at'))
            ->with('family')
            ->get()
            ->sortByDesc(fn (FamilyMembership $membership): bool => $membership->family_id === ($user->current_family_id ?? $user->family_id))
            ->first();

        return $membership === null ? null : route('dashboard', ['current_family' => $membership->family->slug]);
    }
}
