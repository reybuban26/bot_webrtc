<?php

namespace App\Filament\Resources\ApiLogs\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Table;

class ApiLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('service')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                \Filament\Tables\Columns\TextColumn::make('endpoint')
                    ->searchable()
                    ->limit(30),
                \Filament\Tables\Columns\TextColumn::make('method')
                    ->badge(),
                \Filament\Tables\Columns\TextColumn::make('status_code')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        $state >= 200 && $state < 300 => 'success',
                        $state >= 400 => 'danger',
                        default => 'warning',
                    }),
                \Filament\Tables\Columns\TextColumn::make('duration_ms')
                    ->suffix(' ms')
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
