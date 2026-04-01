<?php

namespace App\Filament\Widgets;

use App\Models\ChatMessage;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentMessagesWidget extends BaseWidget
{
    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                fn (): Builder => ChatMessage::query()
                    ->with('session')
                    ->latest()
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('session.title')
                    ->label('Session')
                    ->limit(25),
                TextColumn::make('role')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'user'      => 'info',
                        'assistant' => 'success',
                        default     => 'warning',
                    }),
                TextColumn::make('content')
                    ->label('Message')
                    ->limit(100),
                TextColumn::make('created_at')
                    ->label('Time')
                    ->since(),
            ]);
    }
}
