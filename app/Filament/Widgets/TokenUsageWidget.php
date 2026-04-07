<?php

namespace App\Filament\Widgets;

use App\Models\ChatMessage;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;
use Illuminate\Support\Carbon;

class TokenUsageWidget extends ApexChartWidget
{
    protected static ?string $chartId = 'tokenUsageChart';
    protected static ?string $heading = 'AI Token Usage (Last 30 Days)';
    protected static ?int $sort = 5;
    protected int|string|array $columnSpan = 'full';

    protected function getOptions(): array
    {
        // Generate last 30 days in Asia/Manila
        $days = collect(range(29, 0))->map(
            fn($i) => Carbon::now('Asia/Manila')->startOfDay()->subDays($i)
        );

        $labels = $days->map(fn($d) => $d->format('M d'))->toArray();

        $tokens = $days->map(function ($day) {
            return (int) ChatMessage::whereDate('created_at', $day->toDateString())
                ->where('role', 'assistant')
                ->whereNotNull('tokens_used')
                ->sum('tokens_used');
        })->toArray();

        // Estimated cost: ₱0.03 per 1K tokens
        $costs = array_map(fn($t) => round($t / 1000 * 0.03, 4), $tokens);

        return [
            'chart' => [
                'type'    => 'line',
                'height'  => 300,
                'toolbar' => ['show' => false],
                'animations' => ['enabled' => true, 'speed' => 600],
            ],
            'series' => [
                [
                    'name' => 'Tokens Used',
                    'data' => array_values($tokens),
                    'type' => 'bar',
                ],
                [
                    'name' => 'Est. Cost (PHP)',
                    'data' => array_values($costs),
                    'type' => 'line',
                ],
            ],
            'xaxis' => [
                'categories' => array_values($labels),
                'labels'     => [
                    'style'  => ['fontSize' => '10px'],
                    'rotate' => -45,
                ],
            ],
            'yaxis' => [
                [
                    'title'  => ['text' => 'Tokens'],
                ],
                [
                    'opposite' => true,
                    'title'    => ['text' => 'Cost (PHP)'],
                ],
            ],
            'colors'      => ['#6366f1', '#f59e0b'],
            'stroke'      => ['width' => [0, 2], 'curve' => 'smooth'],
            'plotOptions' => ['bar' => ['borderRadius' => 3, 'columnWidth' => '60%']],
            'dataLabels'  => ['enabled' => false],
            'grid'        => ['borderColor' => '#e2e8f0', 'strokeDashArray' => 4],
            'legend'      => ['position' => 'top'],
            'tooltip'     => [
                'shared'    => true,
                'intersect' => false,
            ],
        ];
    }
}