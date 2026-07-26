<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\PlatformFeatureRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

class FeatureFlags extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $navigationLabel = 'Feature Flags';

    protected string $view = 'filament.pages.feature-flags';

    /**
     * @return array<int, array{key: string, name: string, description: string, status: string, activated_count: int, total_resolved: int, activated_user_ids: list<int>}>
     */
    public function getFeatures(): array
    {
        return PlatformFeatureRegistry::all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('activateForEveryone')
                ->label('Activate for everyone')
                ->schema([
                    Select::make('feature')
                        ->options($this->featureOptions())
                        ->in(fn (): array => array_keys($this->featureOptions()))
                        ->required(),
                ])
                ->action(
                    /**
                     * @param  array<int|string, mixed>  $data
                     */
                    function (array $data): void {
                        $meta = $this->resolveFeatureFromActionData($data);

                        DB::table('features')
                            ->where('name', $meta['class'])
                            ->where('scope', '!=', '')
                            ->where('value', 'false')
                            ->delete();

                        DB::table('features')->updateOrInsert(
                            ['name' => $meta['class'], 'scope' => ''],
                            ['value' => 'true', 'created_at' => now(), 'updated_at' => now()]
                        );

                        Feature::flushCache();

                        Notification::make()
                            ->success()
                            ->title("\"{$meta['name']}\" has been activated for everyone.")
                            ->send();
                    }
                ),
            Action::make('deactivateForEveryone')
                ->label('Deactivate for everyone')
                ->color('danger')
                ->schema([
                    Select::make('feature')
                        ->options($this->featureOptions())
                        ->in(fn (): array => array_keys($this->featureOptions()))
                        ->required(),
                ])
                ->action(
                    /**
                     * @param  array<int|string, mixed>  $data
                     */
                    function (array $data): void {
                        $meta = $this->resolveFeatureFromActionData($data);

                        DB::table('features')
                            ->where('name', $meta['class'])
                            ->delete();

                        Feature::flushCache();

                        Notification::make()
                            ->success()
                            ->title("\"{$meta['name']}\" has been deactivated for everyone.")
                            ->send();
                    }
                ),
            Action::make('activateForUser')
                ->label('Activate for user')
                ->schema($this->userFeatureActionSchema())
                ->action(
                    /**
                     * @param  array<int|string, mixed>  $data
                     */
                    function (array $data): void {
                        $meta = $this->resolveFeatureFromActionData($data);
                        $user = $this->resolveUserFromActionData($data);

                        Feature::for($user)->activate($meta['class']);

                        Notification::make()
                            ->success()
                            ->title("\"{$meta['name']}\" has been activated for {$user->name}.")
                            ->send();
                    }
                ),
            Action::make('deactivateForUser')
                ->label('Deactivate for user')
                ->color('warning')
                ->schema($this->userFeatureActionSchema())
                ->action(
                    /**
                     * @param  array<int|string, mixed>  $data
                     */
                    function (array $data): void {
                        $meta = $this->resolveFeatureFromActionData($data);
                        $user = $this->resolveUserFromActionData($data);

                        Feature::for($user)->deactivate($meta['class']);
                        Feature::flushCache();

                        Notification::make()
                            ->success()
                            ->title("\"{$meta['name']}\" has been deactivated for {$user->name}.")
                            ->send();
                    }
                ),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function featureOptions(): array
    {
        return collect(PlatformFeatureRegistry::options())
            ->mapWithKeys(fn (array $meta, string $key): array => [$key => $meta['name']])
            ->all();
    }

    /**
     * @return array<int, Select>
     */
    private function userFeatureActionSchema(): array
    {
        return [
            Select::make('feature')
                ->options($this->featureOptions())
                ->in(fn (): array => array_keys($this->featureOptions()))
                ->required(),
            Select::make('user_id')
                ->label('User')
                ->options(fn (): array => PlatformFeatureRegistry::userOptions())
                ->exists(User::class, 'id')
                ->searchable()
                ->required(),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array{class: class-string, name: string, description: string}
     */
    private function resolveFeatureFromActionData(array $data): array
    {
        $feature = $data['feature'] ?? null;

        abort_unless(is_string($feature), 422);

        return PlatformFeatureRegistry::resolve($feature);
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private function resolveUserFromActionData(array $data): User
    {
        $userId = $data['user_id'] ?? null;

        abort_unless(is_numeric($userId), 422);

        return User::query()->findOrFail((int) $userId);
    }
}
