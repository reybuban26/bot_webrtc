<?php

namespace App\Filament\Widgets;

use App\Models\CallRequest;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;
use Illuminate\Support\Carbon;

class CallDurationWidget extends ApexChartWidget
{
    protected static ?string $chartId = 'callDurationChart';
    protected static ?string $heading = 'Average Call Duration (seconds)';
    protected static ?int $sort = 3;
    protected int|string|array $columnSpan = 'full';

    protected function getOptions(): array
    {
        // Last 14 days
        $days = collect(range(13, 0))->map(fn($i) => Carbon::today()->subDays($i));

        $labels = $days->map(fn($d) => $d->format('M d'))->toArray();

        $averages = $days->map(function ($day) {
            return (float) CallRequest::whereDate('created_at', $day)
                ->whereNotNull('duration')
                ->where('duration', '>', 0)
                ->avg('duration') ?? 0;
        })->map(fn($v) => round($v, 1))->toArray();

        return [
            'chart' => [
                'type'    => 'area',
                'height'  => 280,
                'toolbar' => ['show' => false],
                'animations' => ['enabled' => true, 'speed' => 600],
            ],
            'series' => [
                [
                    'name' => 'Avg Duration (s)',
                    'data' => $averages,
                ],
            ],
            'xaxis' => [
                'categories' => $labels,
                'labels'     => ['style' => ['fontSize' => '11px']],
            ],
            'yaxis' => [
                'labels' => [
                    'formatter' => 'function(val){ return val + "s" }',
                ],
            ],
            'fill' => [
                'type'     => 'gradient',
                'gradient' => [
                    'shadeIntensity' => 1,
                    'opacityFrom'    => 0.45,
                    'opacityTo'      => 0.05,
                    'stops'          => [0, 100],
                ],
            ],
            'colors'      => ['#6366f1'],
            'stroke'      => ['curve' => 'smooth', 'width' => 2],
            'dataLabels'  => ['enabled' => false],
            'grid'        => ['borderColor' => '#e2e8f0', 'strokeDashArray' => 4],
            'tooltip'     => ['y' => ['formatter' => 'function(val){ return val + " seconds" }']],
        ];
    }
}