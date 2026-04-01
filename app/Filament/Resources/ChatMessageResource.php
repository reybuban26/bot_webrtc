<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChatMessageResource\Pages;
use App\Models\ChatMessage;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ChatMessageResource extends Resource
{
    protected static ?string $model = ChatMessage::class;
    protected static ?string $navigationLabel = 'Messages';
    protected static ?int $navigationSort = 2;

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-chat-bubble-bottom-center-text';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('chat_session_id')
                ->label('Session')
                ->relationship('session', 'title')
                ->disabled(),

            Select::make('role')
                ->label('Role')
                ->options([
                    'user'      => 'User',
                    'assistant' => 'Assistant',
                    'system'    => 'System',
                ])
                ->disabled(),

            Textarea::make('content')
                ->label('Message Content')
                ->rows(10)
                ->columnSpanFull()
                ->disabled(),

            TextInput::make('tokens_used')
                ->label('Tokens Used')
                ->disabled(),

            DateTimePicker::make('created_at')
                ->label('Sent At')
                ->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable()->width('60'),
                TextColumn::make('session.title')
                    ->label('Session')
                    ->limit(30)
                    ->searchable(),
                TextColumn::make('role')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'user'      => 'info',
                        'assistant' => 'success',
                        'system'    => 'warning',
                        default     => 'gray',
                    }),
                TextColumn::make('content')
                    ->label('Content')
                    ->limit(80)
                    ->searchable()
                    ->tooltip(fn ($record) => $record->content),
                TextColumn::make('tokens_used')
                    ->label('Tokens')
                    ->sortable()
                    ->badge()
                    ->color('gray'),
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime('M j, H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options([
                        'user'      => 'User',
                        'assistant' => 'Assistant',
                        'system'    => 'System',
                    ]),
            ])
            ->recordActions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->groupedBulkActions([
                \Filament\Actions\DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChatMessages::route('/'),
        ];
    }
}
