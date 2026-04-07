<?php

namespace App\Filament\Widgets;

use App\Models\SupportThread;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;
use Illuminate\Support\Carbon;

class TicketsResolvedWidget extends ApexChartWidget
{
    protected static ?string $chartId = 'ticketsResolvedChart';
    protected static ?string $heading = 'Support Tickets Resolved per Day';
    protected static ?int $sort = 4;
    protected int|string|array $columnSpan = 'full';

    protected function getOptions(): array
    {
        $days = collect(range(13, 0))->map(fn($i) => Carbon::today()->subDays($i));

        $labels = $days->map(fn($d) => $d->format('M d'))->toArray();

        $resolved = $days->map(function ($day) {
            return SupportThread::whereDate('resolved_at', $day)
                ->where('status', 'resolved')
                ->count();
        })->toArray();

        $opened = $days->map(function ($day) {
            return SupportThread::whereDate('created_at', $day)->count();
        })->toArray();

        return [
            'chart' => [
                'type'    => 'bar',
                'height'  => 280,
                'toolbar' => ['show' => false],
                'animations' => ['enabled' => true, 'speed' => 600],
                'stacked' => false,
            ],
            'series' => [
                [
                    'name' => 'Resolved',
                    'data' => $resolved,
                ],
                [
                    'name' => 'Opened',
                    'data' => $opened,
                ],
            ],
            'xaxis' => [
                'categories' => $labels,
                'labels'     => ['style' => ['fontSize' => '11px']],
            ],
            'yaxis' => [
                'labels' => ['formatter' => 'function(val){ return Math.floor(val) }'],
            ],
            'colors'     => ['#10b981', '#6366f1'],
            'plotOptions' => [
                'bar' => [
                    'borderRadius'     => 4,
                    'columnWidth'      => '55%',
                ],
            ],
            'dataLabels' => ['enabled' => false],
            'grid'       => ['borderColor' => '#e2e8f0', 'strokeDashArray' => 4],
            'legend'     => ['position' => 'top'],
            'tooltip'    => ['shared' => true, 'intersect' => false],
        ];
    }
}