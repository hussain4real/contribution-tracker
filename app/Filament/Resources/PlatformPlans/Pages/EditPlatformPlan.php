<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformPlans\Pages;

use App\Filament\Resources\PlatformPlans\PlatformPlanResource;
use App\Models\PlatformPlan;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPlatformPlan extends EditRecord
{
    protected static string $resource = PlatformPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (DeleteAction $action, PlatformPlan $record): void {
                    if (! $record->families()->exists()) {
                        return;
                    }

                    Notification::make()
                        ->danger()
                        ->title('Cannot delete a plan that has families assigned to it.')
                        ->send();

                    $action->halt();
                }),
        ];
    }
}
