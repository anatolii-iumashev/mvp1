<?php

namespace Tests\Feature;

use App\Contracts\TelephonyClient;
use App\Exceptions\TemporaryTelephonyException;
use App\Jobs\DispatchOutboxEventJob;
use App\Models\Call;
use App\Models\Operator;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DispatchOutboxEventJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_the_outbox_event_as_sent_and_dispatches_the_call(): void
    {
        $operator = Operator::query()->create([
            'name' => 'Alice',
            'available' => false,
        ]);

        $call = Call::query()->create([
            'phone' => '+77001234567',
            'status' => 'assigned',
            'operator_id' => $operator->id,
            'assigned_at' => now(),
        ]);

        $event = OutboxEvent::query()->create([
            'type' => 'call.assigned',
            'aggregate_type' => 'call',
            'aggregate_id' => $call->id,
            'payload_json' => [
                'call_id' => $call->id,
                'operator_id' => $operator->id,
            ],
            'status' => 'pending',
            'attempts' => 0,
        ]);

        app()->bind(TelephonyClient::class, fn () => new class implements TelephonyClient {
            public function sendCallAssigned(int $callId, int $operatorId, string $idempotencyKey): void
            {
            }
        });

        app()->call([new DispatchOutboxEventJob($event->id), 'handle']);

        $event->refresh();
        $call->refresh();

        $this->assertSame('sent', $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertNull($event->next_retry_at);
        $this->assertSame('dispatched', $call->status);
        $this->assertNotNull($call->dispatched_at);
    }

    public function test_it_requeues_the_outbox_event_after_a_temporary_telephony_failure(): void
    {
        $operator = Operator::query()->create([
            'name' => 'Alice',
            'available' => false,
        ]);

        $call = Call::query()->create([
            'phone' => '+77001234567',
            'status' => 'assigned',
            'operator_id' => $operator->id,
            'assigned_at' => now(),
        ]);

        $event = OutboxEvent::query()->create([
            'type' => 'call.assigned',
            'aggregate_type' => 'call',
            'aggregate_id' => $call->id,
            'payload_json' => [
                'call_id' => $call->id,
                'operator_id' => $operator->id,
            ],
            'status' => 'pending',
            'attempts' => 0,
        ]);

        app()->bind(TelephonyClient::class, fn () => new class implements TelephonyClient {
            public function sendCallAssigned(int $callId, int $operatorId, string $idempotencyKey): void
            {
                throw new TemporaryTelephonyException('telephony timeout');
            }
        });

        $this->expectException(TemporaryTelephonyException::class);

        try {
            app()->call([new DispatchOutboxEventJob($event->id), 'handle']);
        } finally {
            $event->refresh();
            $call->refresh();
        }

        $this->assertSame('pending', $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertNotNull($event->next_retry_at);
        $this->assertSame('telephony timeout', $event->last_error);
        $this->assertSame('assigned', $call->status);
        $this->assertNull($call->dispatched_at);
    }
}
