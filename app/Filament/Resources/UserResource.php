<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationLabel = 'User Management';
    protected static ?string $modelLabel = 'User';
    protected static ?int $navigationSort = 0;

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-users';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),

            Select::make('role')
                ->label('Role')
                ->options([
                    'user'  => '👤 User — Chat only',
                    'admin' => '🛡️ Admin — Full panel access',
                ])
                ->required()
                ->native(false),

            TextInput::make('phone')
                ->label('Phone Number')
                ->tel()
                ->maxLength(30)
                ->nullable(),

            \Filament\Forms\Components\DatePicker::make('dob')
                ->label('Date of Birth')
                ->nullable(),

            TextInput::make('password')
                ->password()
                ->label('New Password (leave blank to keep current)')
                ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                ->dehydrated(fn ($state) => filled($state))
                ->nullable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                    
                \Filament\Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'admin' => '🛡️ Admin',
                        default => '👤 User',
                    })
                    ->sortable(),

                TextColumn::make('chatSessions_count')
                    ->label('Sessions')
                    ->counts('chatSessions')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Registered')
                    ->dateTime('M d, Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options([
                        'user'  => 'User',
                        'admin' => 'Admin',
                    ]),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make()->label('Manage'),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->groupedBulkActions([
                \Filament\Actions\DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'edit'  => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
