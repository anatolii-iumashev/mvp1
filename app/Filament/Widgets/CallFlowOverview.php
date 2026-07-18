<?php

namespace App\Filament\Widgets;

use App\Models\Call;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CallFlowOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Call Flow';

    protected ?string $description = 'Текущий backlog и движение звонков через назначение и dispatch.';

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $now = now();
        $startOfDay = $now->copy()->startOfDay();
        $yesterdayStart = $startOfDay->copy()->subDay();
        $yesterdayEnd = $startOfDay->copy()->subSecond();

        $statusCounts = Call::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $newCalls = (int) ($statusCounts['new'] ?? 0);
        $assignedCalls = (int) ($statusCounts['assigned'] ?? 0);
        $failedCalls = (int) ($statusCounts['failed'] ?? 0);
        $staleAssignedCalls = Call::query()
            ->where('status', 'assigned')
            ->whereNotNull('assigned_at')
            ->where('assigned_at', '<=', $now->copy()->subMinutes(5))
            ->count();

        $dispatchedToday = Call::query()
            ->whereNotNull('dispatched_at')
            ->whereBetween('dispatched_at', [$startOfDay, $now])
            ->count();

        $dispatchedYesterday = Call::query()
            ->whereNotNull('dispatched_at')
            ->whereBetween('dispatched_at', [$yesterdayStart, $yesterdayEnd])
            ->count();

        $failedToday = Call::query()
            ->where('status', 'failed')
            ->whereBetween('updated_at', [$startOfDay, $now])
            ->count();

        $oldestNewCallAt = Call::query()
            ->where('status', 'new')
            ->oldest('created_at')
            ->value('created_at');

        return [
            Stat::make('New queue', number_format($newCalls))
                ->description($oldestNewCallAt
                    ? 'Oldest waiting: ' . $oldestNewCallAt->diffForHumans($now, short: true, parts: 2)
                    : 'No new calls waiting')
                ->color($newCalls > 0 ? 'warning' : 'success'),
            Stat::make('Assigned pending dispatch', number_format($assignedCalls))
                ->description($staleAssignedCalls > 0
                    ? number_format($staleAssignedCalls) . ' older than 5 minutes'
                    : 'No stale assigned calls')
                ->color($staleAssignedCalls > 0 ? 'danger' : ($assignedCalls > 0 ? 'warning' : 'success')),
            Stat::make('Dispatched today', number_format($dispatchedToday))
                ->description($this->describeDelta($dispatchedToday, $dispatchedYesterday, 'vs yesterday'))
                ->color('success'),
            Stat::make('Failed calls', number_format($failedCalls))
                ->description(number_format($failedToday) . ' updated to failed today')
                ->color($failedCalls > 0 ? 'danger' : 'success'),
        ];
    }

    private function describeDelta(int $current, int $previous, string $suffix): string
    {
        $delta = $current - $previous;

        if ($delta === 0) {
            return 'No change ' . $suffix;
        }

        return sprintf(
            '%s%s %s',
            $delta > 0 ? '+' : '',
            number_format($delta),
            $suffix,
        );
    }
}
