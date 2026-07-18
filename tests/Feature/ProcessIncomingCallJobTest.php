<?php

namespace Tests\Feature;

use App\Exceptions\NoAvailableOperatorException;
use App\Jobs\ProcessIncomingCallJob;
use App\Models\Call;
use App\Models\Client;
use App\Models\Operator;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessIncomingCallJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_assigns_a_new_call_and_creates_an_outbox_event(): void
    {
        $client = Client::query()->create(['phone' => '+77001234567']);
        $operator = Operator::query()->create(['name' => 'Alice']);
        $call = Call::query()->create(['phone' => $client->phone]);

        (new ProcessIncomingCallJob($call->id))->handle();

        $call->refresh();
        $operator->refresh();

        $this->assertSame('assigned', $call->status);
        $this->assertSame($client->id, $call->client_id);
        $this->assertSame($operator->id, $call->operator_id);
        $this->assertNotNull($call->assigned_at);
        $this->assertSame(1, $call->attempts_assign);
        $this->assertFalse($operator->available);

        $this->assertDatabaseHas('outbox_events', [
            'type' => 'call.assigned',
            'aggregate_type' => 'call',
            'aggregate_id' => $call->id,
            'status' => 'pending',
        ]);
    }

    public function test_it_is_idempotent_when_a_call_is_processed_twice(): void
    {
        $operator = Operator::query()->create(['name' => 'Alice']);
        $call = Call::query()->create(['phone' => '+77001234567']);
        $job = new ProcessIncomingCallJob($call->id);

        $job->handle();
        $job->handle();

        $call->refresh();
        $operator->refresh();

        $this->assertSame('assigned', $call->status);
        $this->assertSame(1, $call->attempts_assign);
        $this->assertSame($operator->id, $call->operator_id);
        $this->assertFalse($operator->available);
        $this->assertSame(1, OutboxEvent::query()->count());
    }

    public function test_it_throws_when_no_operator_is_available(): void
    {
        $call = Call::query()->create(['phone' => '+77001234567']);

        $this->expectException(NoAvailableOperatorException::class);

        try {
            (new ProcessIncomingCallJob($call->id))->handle();
        } finally {
            $call->refresh();
        }

        $this->assertSame('new', $call->status);
        $this->assertSame(0, $call->attempts_assign);
        $this->assertNull($call->operator_id);
        $this->assertSame(0, OutboxEvent::query()->count());
    }
}
