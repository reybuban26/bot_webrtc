<?php

namespace App\Filament\Resources\ApiLogs\Schemas;

use Filament\Schemas\Schema;

class ApiLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\TextInput::make('service'),
                \Filament\Forms\Components\TextInput::make('endpoint'),
                \Filament\Forms\Components\TextInput::make('method'),
                \Filament\Forms\Components\TextInput::make('status_code'),
                \Filament\Forms\Components\TextInput::make('duration_ms')->suffix('ms'),
                \Filament\Forms\Components\TextInput::make('ip_address'),
                
                \Filament\Forms\Components\KeyValue::make('request_payload')
                    ->columnSpanFull(),
                \Filament\Forms\Components\KeyValue::make('response_payload')
                    ->columnSpanFull(),
                    
                \Filament\Forms\Components\Textarea::make('error_message')
                    ->columnSpanFull(),
            ]);
    }
}
