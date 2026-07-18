<?php

namespace App\Filament\Widgets;

use App\Models\Call;
use App\Models\Operator;
use App\Models\OutboxEvent;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationsHealthOverview extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = -9;

    protected ?string $heading = 'Operations Health';

    protected ?string $description = 'Доступность операторов и состояние очереди доставки в телефонию.';

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $now = now();

        $activeOperators = Operator::query()
            ->where('active', true)
            ->count();

        $availableOperators = Operator::query()
            ->where('active', true)
            ->where('available', true)
            ->count();

        $busyOperators = max(0, $activeOperators - $availableOperators);
        $busyRate = $activeOperators > 0
            ? (int) round(($busyOperators / $activeOperators) * 100)
            : 0;

        $pendingDispatchesDue = OutboxEvent::query()
            ->where('status', 'pending')
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', $now);
            })
            ->count();

        $retryingDispatches = OutboxEvent::query()
            ->where('status', 'pending')
            ->where('attempts', '>', 1)
            ->count();

        $failedOutboxEvents = OutboxEvent::query()
            ->where('status', 'failed')
            ->count();

        $attentionCalls = Call::query()
            ->where('status', 'failed')
            ->orWhere(function ($query): void {
                $query->where('status', 'assigned')
                    ->whereNotNull('assigned_at')
                    ->where('assigned_at', '<=', now()->subMinutes(5));
            })
            ->count();

        return [
            Stat::make('Active operators', number_format($activeOperators))
                ->description(number_format($availableOperators) . ' currently available')
                ->color($activeOperators > 0 ? 'success' : 'gray'),
            Stat::make('Busy operators', number_format($busyOperators))
                ->description($busyRate . '% of active pool is busy')
                ->color($busyRate >= 80 ? 'warning' : 'success'),
            Stat::make('Pending outbox due now', number_format($pendingDispatchesDue))
                ->description(number_format($retryingDispatches) . ' pending events are already retrying')
                ->color($pendingDispatchesDue > 0 ? 'warning' : 'success'),
            Stat::make('Needs attention', number_format($attentionCalls + $failedOutboxEvents))
                ->description(number_format($failedOutboxEvents) . ' failed outbox events, ' . number_format($attentionCalls) . ' problematic calls')
                ->color(($attentionCalls + $failedOutboxEvents) > 0 ? 'danger' : 'success'),
        ];
    }
}
