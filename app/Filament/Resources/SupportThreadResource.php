<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupportThreadResource\Pages;
use App\Models\SupportThread;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class SupportThreadResource extends Resource
{
    protected static ?string $model = SupportThread::class;
    protected static ?string $navigationLabel = 'Support Conversations';
    protected static ?string $modelLabel = 'Support Conversation';
    protected static ?int $navigationSort = 2; // Right after Chat Sessions

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-phone-arrow-up-right';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('user_name')
                ->label('User')
                ->content(fn ($record) => $record?->user?->name ?? 'Unknown'),

            Placeholder::make('message_count')
                ->label('Total Messages')
                ->content(fn ($record) => $record ? $record->messages()->count() . ' messages' : '-'),

            DateTimePicker::make('updated_at')
                ->label('Last Activity')
                ->timezone('Asia/Manila')
                ->format('M j, Y h:i A')
                ->disabled(),

            Placeholder::make('conversation_history')
                ->label('Conversation History')
                ->columnSpanFull()
                ->content(function ($record) {
                    if (!$record) return '';
                    $html = '<div style="background: rgba(100, 116, 139, 0.05); border: 1px solid rgba(100, 116, 139, 0.2); border-radius: 12px; padding: 24px; max-height: 500px; overflow-y: auto; display: flex; flex-direction: column; gap: 24px;">';
                    
                    foreach ($record->messages()->orderBy('created_at')->get() as $m) {
                        $isUser = $m->sender_id === $record->user_id;
                        $role = $isUser ? 'USER' : 'ADMIN';
                        $time = $m->created_at->setTimezone('Asia/Manila')->format('M j, Y, h:i A');
                        
                        $align = $isUser ? 'align-self: flex-end; align-items: flex-end;' : 'align-self: flex-start; align-items: flex-start;';
                        $bg = $isUser ? 'background: #6366f1; color: #ffffff;' : 'background: rgba(100, 116, 139, 0.1); color: inherit;';
                        $roleColor = $isUser ? 'color: #6366f1;' : 'color: #8b5cf6;';
                        $headerAlign = $isUser ? 'flex-direction: row-reverse;' : '';
                        
                        $html .= "<div style='display: flex; flex-direction: column; gap: 6px; max-width: 85%; {$align}'>";
                        $html .= "<div style='display: flex; gap: 8px; font-size: 12px; font-weight: 500; opacity: 0.8; {$headerAlign}'>";
                        $html .= "<span style='{$roleColor} font-weight: 700;'>{$role}</span>";
                        $html .= "<span>{$time}</span>";
                        $html .= "</div>";
                        
                        $html .= "<div style='{$bg} padding: 14px 18px; border-radius: 16px; font-size: 14px; line-height: 1.5; box-shadow: 0 1px 2px rgba(0,0,0,0.05);'>";
                        
                        // Audio player for recordings
                        if ($m->type === 'meeting_notes' && is_array($m->metadata) && isset($m->metadata['recording_url'])) {
                            
                            // Vanilla JS version ng infinity duration fix para sa Filament backend
                            $fixJs = "if(this.duration===Infinity||isNaN(this.duration)){this.currentTime=1e101;var el=this;this.addEventListener('timeupdate',function fix(){el.removeEventListener('timeupdate',fix);el.currentTime=0;})}";

                            $html .= "<div style='margin-bottom: 12px;'>";
                            $html .= "<audio controls preload='auto' src='{$m->metadata['recording_url']}' onloadedmetadata=\"{$fixJs}\" style='height: 36px; max-width: 100%; border-radius: 20px;'></audio>";
                            $html .= "</div>";
                        }
                        
                        $body = nl2br(htmlspecialchars($m->body ?? ''));
                        if ($m->type === 'meeting_notes') {
                            $notesColor = $isUser ? 'color: #e0e7ff;' : 'color: #8b5cf6;';
                            $body = "<strong style='{$notesColor}'>[📞 MEETING NOTES]</strong><br>" . $body;
                        }
                        
                        $html .= "<div style='white-space: pre-wrap; word-break: break-word;'>{$body}</div>";
                        $html .= "</div></div>";
                    }
                    
                    $html .= '</div>';
                    return new HtmlString($html);
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable()->width('60'),
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('messages_count')
                    ->label('Messages')
                    ->counts('messages')
                    ->badge()
                    ->color('info'),
                TextColumn::make('updated_at')
                    ->label('Last Activity')
                    ->dateTime('M j, Y h:i A', 'Asia/Manila')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->groupedBulkActions([
                \Filament\Actions\DeleteBulkAction::make(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportThreads::route('/'),
            'view'  => Pages\ViewSupportThread::route('/{record}'),
        ];
    }
}
