<?php

namespace App\Filament\Widgets;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use App\Models\SupportThread;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ChatStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;
    protected ?string $pollingInterval = '3s'; // Live update exactly like the inbox

    protected function getStats(): array
    {
        $totalSessions = ChatSession::count();
        $totalSupport  = SupportThread::count();
        $totalMessages = ChatMessage::count();
        $messagesToday = ChatMessage::whereDate('created_at', today())->count();
        $totalTokens   = ChatMessage::sum('tokens_used');

        return [
            Stat::make('Total Sessions', $totalSessions)
                ->description('AI chat sessions')
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color('info')
                ->chart([max(0, $totalSessions - 5), $totalSessions]),

            Stat::make('Support Conversations', $totalSupport)
                ->description('Active user support chats')
                ->descriptionIcon('heroicon-m-phone-arrow-up-right')
                ->color('primary')
                ->chart([max(0, $totalSupport - 5), $totalSupport]),

            Stat::make('Total Messages', $totalMessages)
                ->description('All-time AI messages')
                ->descriptionIcon('heroicon-m-chat-bubble-bottom-center-text')
                ->color('success'),

            Stat::make('AI Responses', ChatMessage::where('role', 'assistant')->count())
                ->description(number_format($totalTokens) . ' tokens used')
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color('warning'),
        ];
    }
}
