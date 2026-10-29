<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\WhatsAppMessages\Widgets\WhatsAppDeliveryStats;
use App\Filament\Widgets\PlatformStatsOverview;
use App\Filament\Widgets\RecentFamilies;
use App\Filament\Widgets\RecentPayments;
use App\Models\User;

it('renders D1 dashboard statistics and tables in the initial authenticated GET response', function (bool $migration): void {
    config(['d1-production.enabled' => true, 'd1-production.migration_checks' => $migration]);
    $this->actingAs(User::factory()->admin()->superAdmin()->create());

    $this->get(Dashboard::getUrl())->assertSuccessful()
        ->assertSee('Total families')->assertSee('Total users')
        ->assertSee('Recent families')->assertSee('Recent payments');
})->with([true, false]);

it('keeps D1 platform widgets eager and preserves lazy rendering on PostgreSQL', function (bool $production, bool $migration, bool $lazy): void {
    config(['d1-production.enabled' => $production, 'd1-production.migration_checks' => $migration]);
    foreach ([PlatformStatsOverview::class, RecentFamilies::class, RecentPayments::class] as $widget) {
        expect($widget::isLazy())->toBe($lazy);
    }
    expect(WhatsAppDeliveryStats::isLazy())->toBe(! ($production && $migration));
})->with([[true, true, false], [true, false, false], [false, true, true], [false, false, true]]);

it('does not allow a regular family admin into the eagerly rendered platform dashboard', function (): void {
    config(['d1-production.enabled' => true, 'd1-production.migration_checks' => true]);
    $this->actingAs(User::factory()->admin()->create());
    $this->get(Dashboard::getUrl())->assertForbidden();
});
