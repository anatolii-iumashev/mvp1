<?php

namespace App\Jobs;

use App\Contracts\TelephonyClient;
use App\Exceptions\PermanentTelephonyException;
use App\Exceptions\TemporaryTelephonyException;
use App\Models\Call;
use App\Models\OutboxEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DispatchOutboxEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $outboxEventId)
    {
        $this->onQueue('telephony_dispatch');
    }

    public function handle(TelephonyClient $telephonyClient): void
    {
        $event = $this->claimEvent();

        if ($event === null) {
            return;
        }

        $payload = $event->payload_json;
        $callId = (int) ($payload['call_id'] ?? $event->aggregate_id);
        $operatorId = (int) ($payload['operator_id'] ?? 0);

        if ($operatorId === 0) {
            $this->markFailed($event->id, 'Missing operator_id in payload');

            return;
        }

        try {
            $telephonyClient->sendCallAssigned(
                $callId,
                $operatorId,
                sprintf('call:%d:assigned', $callId),
            );

            $this->markSent($event->id, $callId);
        } catch (TemporaryTelephonyException $exception) {
            $this->scheduleRetry($event->id, $exception->getMessage(), $event->attempts);

            throw $exception;
        } catch (PermanentTelephonyException $exception) {
            $this->markFailed($event->id, $exception->getMessage());
        }
    }

    public function backoff(): array
    {
        return [2, 5, 10, 20, 30];
    }

    private function claimEvent(): ?OutboxEvent
    {
        return DB::transaction(function (): ?OutboxEvent {
            $query = OutboxEvent::query()
                ->whereKey($this->outboxEventId)
                ->where('status', 'pending')
                ->where(function ($query): void {
                    $query->whereNull('next_retry_at')
                        ->orWhere('next_retry_at', '<=', now());
                });

            if ($this->supportsSkipLocked()) {
                $query->lock('for update skip locked');
            } elseif ($this->supportsRowLocks()) {
                $query->lockForUpdate();
            }

            $event = $query->first();

            if ($event === null) {
                return null;
            }

            $event->forceFill([
                'attempts' => $event->attempts + 1,
                'next_retry_at' => now()->addMinute(),
                'last_error' => null,
            ])->save();

            return $event->fresh();
        }, 5);
    }

    private function markSent(int $eventId, int $callId): void
    {
        DB::transaction(function () use ($eventId, $callId): void {
            $event = OutboxEvent::query()->whereKey($eventId);
            $call = Call::query()->whereKey($callId);

            if ($this->supportsRowLocks()) {
                $event->lockForUpdate();
                $call->lockForUpdate();
            }

            $eventRecord = $event->first();
            $callRecord = $call->first();

            if ($eventRecord === null) {
                return;
            }

            $eventRecord->forceFill([
                'status' => 'sent',
                'next_retry_at' => null,
                'last_error' => null,
            ])->save();

            if ($callRecord !== null) {
                $callRecord->forceFill([
                    'status' => 'dispatched',
                    'dispatched_at' => now(),
                    'last_error' => null,
                ])->save();
            }
        }, 5);
    }

    private function scheduleRetry(int $eventId, string $message, int $attempts): void
    {
        OutboxEvent::query()
            ->whereKey($eventId)
            ->update([
                'status' => 'pending',
                'next_retry_at' => Carbon::now()->addSeconds($this->retryDelaySeconds($attempts)),
                'last_error' => $message,
            ]);
    }

    private function markFailed(int $eventId, string $message): void
    {
        OutboxEvent::query()
            ->whereKey($eventId)
            ->update([
                'status' => 'failed',
                'next_retry_at' => null,
                'last_error' => $message,
            ]);
    }

    private function retryDelaySeconds(int $attempts): int
    {
        return min(300, (2 ** max(1, $attempts)) + random_int(0, 3));
    }

    private function supportsRowLocks(): bool
    {
        return DB::connection()->getDriverName() !== 'sqlite';
    }

    private function supportsSkipLocked(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true);
    }
}
