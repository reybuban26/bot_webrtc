<?php

namespace App\Filament\Resources\SupportThreadResource\Pages;

use App\Filament\Resources\SupportThreadResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupportThreads extends ListRecords
{
    protected static string $resource = SupportThreadResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
