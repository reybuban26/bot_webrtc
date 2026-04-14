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

            Placeholder::make('status_info')
                ->label('Status')
                ->columnSpanFull()
                ->content(function ($record) {
                    if (!$record) return '';

                    // ── Resolution (primary: what the admin marked the last session as) ──
                    $resolution = $record->resolution_status ?? null;
                    $resolutionBadge = '<span style="background:#6b7280;color:#fff;padding:3px 12px;border-radius:99px;font-size:12px;font-weight:700;">No resolution yet</span>';
                    if ($resolution === 'resolved') {
                        $resolutionBadge = '<span style="background:#22c55e;color:#fff;padding:3px 12px;border-radius:99px;font-size:12px;font-weight:700;">✔ RESOLVED</span>';
                    } elseif ($resolution === 'pending') {
                        $resolutionBadge = '<span style="background:#f59e0b;color:#fff;padding:3px 12px;border-radius:99px;font-size:12px;font-weight:700;">⏳ PENDING</span>';
                    }

                    // ── Current chat state (secondary: where the thread is RIGHT NOW) ──
                    $status = $record->chat_status ?? 'unknown';
                    $statusColors = [
                        'waiting'        => '#f59e0b',
                        'ai_active'      => '#6366f1',
                        'escalating'     => '#f97316',
                        'active'         => '#22c55e',
                        'ended'          => '#6b7280',
                    ];
                    $color = $statusColors[$status] ?? '#6b7280';
                    $currentBadge = "<span style='background:{$color};color:#fff;padding:3px 12px;border-radius:99px;font-size:12px;font-weight:700;'>" . strtoupper($status) . "</span>";

                    $html  = '<div style="display:flex;flex-direction:column;gap:8px;">';
                    $html .= '<div style="display:flex;align-items:center;gap:10px;">';
                    $html .= '<span style="font-size:12px;font-weight:600;color:#9ca3af;min-width:120px;">Last resolution:</span>';
                    $html .= $resolutionBadge;
                    $html .= '</div>';
                    $html .= '<div style="display:flex;align-items:center;gap:10px;">';
                    $html .= '<span style="font-size:12px;font-weight:600;color:#9ca3af;min-width:120px;">Current state:</span>';
                    $html .= $currentBadge;
                    $html .= '</div>';
                    $html .= '</div>';

                    return new HtmlString($html);
                }),

            Placeholder::make('feedback_info')
                ->label('Customer Feedback')
                ->columnSpanFull()
                ->content(function ($record) {
                    if (!$record) return '';

                    $isResolved = $record->is_resolved_by_user;
                    $rating = $record->feedback_rating;
                    $comment = $record->feedback_comment;

                    if ($isResolved === null && !$rating && !$comment) {
                        return new HtmlString('<span style="color:#6b7280;font-size:13px;">No feedback submitted yet.</span>');
                    }

                    $html = '<div style="display:flex;flex-direction:column;gap:10px;padding:14px;background:rgba(100,116,139,.07);border-radius:10px;border:1px solid rgba(100,116,139,.15);">';

                    // Resolved
                    if ($isResolved !== null) {
                        if ($isResolved) {
                            $html .= '<div style="font-size:13px;">✅ <strong>Issue resolved:</strong> <span style="color:#22c55e;font-weight:700;">Yes</span></div>';
                        } else {
                            $html .= '<div style="font-size:13px;">❌ <strong>Issue resolved:</strong> <span style="color:#ef4444;font-weight:700;">No</span></div>';
                        }
                    }

                    // Star rating
                    if ($rating) {
                        $stars = str_repeat('⭐', $rating) . str_repeat('☆', 5 - $rating);
                        $html .= "<div style='font-size:13px;'><strong>Rating:</strong> {$stars} ({$rating}/5)</div>";
                    }

                    // Comment
                    if ($comment) {
                        $escapedComment = htmlspecialchars($comment);
                        $html .= "<div style='font-size:13px;'><strong>Comment:</strong> <span style='color:#9ca3af;font-style:italic;'>\"{$escapedComment}\"</span></div>";
                    }

                    $html .= '</div>';
                    return new HtmlString($html);
                }),

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
                TextColumn::make('resolution_status')
                    ->label('Resolution')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? strtoupper($state) : 'NO RESOLUTION')
                    ->color(fn ($state) => match ($state) {
                        'resolved' => 'success',
                        'pending'  => 'warning',
                        default    => 'gray',
                    }),
                TextColumn::make('chat_status')
                    ->label('Current State')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'active'     => 'success',
                        'escalating' => 'warning',
                        'ended'      => 'gray',
                        'ai_active'  => 'info',
                        default      => 'secondary',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('feedback_rating')
                    ->label('Rating')
                    ->formatStateUsing(fn ($state) => $state ? str_repeat('⭐', $state) : '—')
                    ->placeholder('—'),
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
            ->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->whereHas('messages'));
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
