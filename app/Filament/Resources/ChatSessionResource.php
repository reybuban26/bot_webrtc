<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChatSessionResource\Pages;
use App\Models\ChatSession;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class ChatSessionResource extends Resource
{
    protected static ?string $model = ChatSession::class;
    protected static ?string $navigationLabel = 'Chat Sessions';
    protected static ?string $modelLabel = 'Chat Session';
    protected static ?int $navigationSort = 1;

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-chat-bubble-left-right';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            Toggle::make('is_active')->label('Active'),

            Placeholder::make('message_count')
                ->label('Total Messages')
                ->content(fn ($record) => $record ? $record->messages()->count() . ' messages' : '-'),

            DateTimePicker::make('created_at')
                ->label('Started At')
                ->disabled(),

            Textarea::make('conversation_preview')
                ->label('Conversation')
                ->rows(16)
                ->columnSpanFull()
                ->disabled()
                ->dehydrated(false)
                ->formatStateUsing(fn ($record) => $record
                    ? $record->messages()
                        ->orderBy('created_at')
                        ->get()
                        ->map(fn ($m) => strtoupper($m->role) . ":\n" . $m->content)
                        ->join("\n\n" . str_repeat('─', 40) . "\n\n")
                    : ''
                ),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable()->width('60'),
                TextColumn::make('title')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->title),
                TextColumn::make('messages_count')
                    ->label('Messages')
                    ->counts('messages')
                    ->badge()
                    ->color('info'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y H:i')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Status'),
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
            'index' => Pages\ListChatSessions::route('/'),
            'view'  => Pages\ViewChatSession::route('/{record}'),
        ];
    }
}
