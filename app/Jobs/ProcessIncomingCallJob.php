<?php

namespace App\Jobs;

use App\Exceptions\NoAvailableOperatorException;
use App\Models\Call;
use App\Models\Client;
use App\Models\Operator;
use App\Models\OutboxEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessIncomingCallJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $callId)
    {
    $this->onQueue('calls_assign');
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $call = $this->lockCall();

            if ($call === null || $call->status !== 'new') {
                return;
            }

            $call->increment('attempts_assign');

            $operator = $this->lockAvailableOperator();

            if ($operator === null) {
                $call->forceFill([
                    'last_error' => 'No available operators',
                ])->save();

                throw new NoAvailableOperatorException('No available operators');
            }

            $operator->forceFill([
                'available' => false,
                'last_call_at' => now(),
            ])->save();

            $clientId = Client::query()
                ->where('phone', $call->phone)
                ->value('id');

            $call->forceFill([
                'client_id' => $clientId,
                'operator_id' => $operator->id,
                'status' => 'assigned',
                'assigned_at' => now(),
                'last_error' => null,
            ])->save();

            OutboxEvent::createUnique(
                type: 'call.assigned',
                aggregateType: 'call',
                aggregateId: $call->id,
                payload: [
                    'call_id' => $call->id,
                    'operator_id' => $operator->id,
                ],
            );
        }, 5);
    }

    public function backoff(): array
    {
        return [1, 2, 5, 10, 20];
    }

    private function lockCall(): ?Call
    {
        $query = Call::query()->whereKey($this->callId);

        if ($this->supportsRowLocks()) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function lockAvailableOperator(): ?Operator
    {
        $query = Operator::query()
            ->where('available', true)
            ->orderByRaw('case when last_call_at is null then 0 else 1 end')
            ->orderBy('last_call_at');

        if ($this->supportsSkipLocked()) {
            $query->lock('for update skip locked');
        } elseif ($this->supportsRowLocks()) {
            $query->lockForUpdate();
        }

        return $query->first();
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
