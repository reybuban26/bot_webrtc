<?php

namespace App\Filament\Widgets;

use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TokenCostWidget extends ApexChartWidget
{
    protected static ?string $chartId = 'tokenCostChart';
    protected static ?string $heading = 'Token Cost Summary (This Month)';
    protected static ?int $sort = 6;
    protected int|string|array $columnSpan = 'full';

    // Cost per 1K tokens — ₱0.03 estimated
    private const COST_PER_1K = 0.03;

    protected function getOptions(): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();

        // Per-user token usage — fully qualified column names to avoid ambiguity
        $perUser = DB::table('chat_messages')
            ->join('chat_sessions', 'chat_messages.chat_session_id', '=', 'chat_sessions.id')
            ->join('users', 'chat_sessions.user_id', '=', 'users.id')
            ->where('chat_messages.role', 'assistant')
            ->where('chat_messages.created_at', '>=', $startOfMonth)
            ->whereNotNull('chat_messages.tokens_used')
            ->selectRaw('users.name, SUM(chat_messages.tokens_used) as total_tokens')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_tokens')
            ->limit(8)
            ->get();

        $labels = $perUser->pluck('name')->toArray();
        $costs  = $perUser->map(fn($r) => round($r->total_tokens / 1000 * self::COST_PER_1K, 4))->toArray();

        // Total cost this month
        $totalTokens = DB::table('chat_messages')
            ->where('chat_messages.role', 'assistant')
            ->where('chat_messages.created_at', '>=', $startOfMonth)
            ->whereNotNull('chat_messages.tokens_used')
            ->sum('chat_messages.tokens_used');
        $totalCost = round($totalTokens / 1000 * self::COST_PER_1K, 4);

        return [
            'chart' => [
                'type'    => 'donut',
                'height'  => 320,
                'toolbar' => ['show' => false],
                'animations' => ['enabled' => true, 'speed' => 600],
            ],
            'series' => $costs ?: [0],
            'labels' => $labels ?: ['No data'],
            'colors' => [
                '#6366f1', '#8b5cf6', '#06b6d4', '#10b981',
                '#f59e0b', '#ef4444', '#ec4899', '#14b8a6',
            ],
            'plotOptions' => [
                'pie' => [
                    'donut' => [
                        'size'   => '65%',
                        'labels' => [
                            'show'  => true,
                            'total' => [
                                'show'      => true,
                                'label'     => 'Total Cost',
                                'formatter' => 'function(w){ return "\u20b1' . $totalCost . '" }',
                            ],
                        ],
                    ],
                ],
            ],
            'dataLabels' => ['enabled' => true],
            'legend'     => ['position' => 'bottom'],
            'tooltip'    => [
                'y' => ['formatter' => 'function(val){ return "\u20b1" + val.toFixed(4) }'],
            ],
        ];
    }
}