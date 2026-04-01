<?php

namespace App\Filament\Resources\SupportThreadResource\Pages;

use App\Filament\Resources\SupportThreadResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSupportThread extends ViewRecord
{
    protected static string $resource = SupportThreadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
