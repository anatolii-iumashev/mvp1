<?php

namespace Database\Seeders;

use App\Models\Call;
use App\Models\Client;
use App\Models\Operator;
use App\Models\OutboxEvent;
use Carbon\CarbonImmutable;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CallCenterDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const INSERT_CHUNK_SIZE = 1000;

    public function run(): void
    {
        if ($this->hasDomainData()) {
            return;
        }

        $now = CarbonImmutable::now();
        $faker = FakerFactory::create();

        $this->seedScenarioData($now);
        $this->seedBulkData($now, $faker);
    }

    private function seedScenarioData(CarbonImmutable $now): void
    {
        $clients = [
            '+77001234567' => Client::query()->create(['phone' => '+77001234567']),
            '+77005554433' => Client::query()->create(['phone' => '+77005554433']),
            '+77009998877' => Client::query()->create(['phone' => '+77009998877']),
            '+77001112233' => Client::query()->create(['phone' => '+77001112233']),
        ];

        $operators = [
            'Alice Johnson' => Operator::query()->create([
                'name' => 'Alice Johnson',
                'available' => true,
                'active' => true,
                'last_call_at' => $now->subMinutes(45),
            ]),
            'Boris Kim' => Operator::query()->create([
                'name' => 'Boris Kim',
                'available' => true,
                'active' => true,
                'last_call_at' => $now->subMinutes(18),
            ]),
            'Chingiz Sadykov' => Operator::query()->create([
                'name' => 'Chingiz Sadykov',
                'available' => false,
                'active' => true,
                'last_call_at' => $now->subMinutes(6),
            ]),
            'Dana Smailova' => Operator::query()->create([
                'name' => 'Dana Smailova',
                'available' => false,
                'active' => false,
                'last_call_at' => $now->subHours(2),
            ]),
        ];

        Call::query()->create([
            'phone' => '+77001234567',
            'status' => 'new',
            'attempts_assign' => 0,
        ]);

        $assignedPendingDispatchCall = Call::query()->create([
            'phone' => '+77005554433',
            'status' => 'assigned',
            'client_id' => $clients['+77005554433']->id,
            'operator_id' => $operators['Chingiz Sadykov']->id,
            'assigned_at' => $now->subMinutes(5),
            'attempts_assign' => 1,
        ]);

        $dispatchedCall = Call::query()->create([
            'phone' => '+77009998877',
            'status' => 'dispatched',
            'client_id' => $clients['+77009998877']->id,
            'operator_id' => $operators['Boris Kim']->id,
            'assigned_at' => $now->subMinutes(28),
            'dispatched_at' => $now->subMinutes(26),
            'attempts_assign' => 1,
        ]);

        $failedDispatchCall = Call::query()->create([
            'phone' => '+77001112233',
            'status' => 'failed',
            'client_id' => $clients['+77001112233']->id,
            'operator_id' => $operators['Alice Johnson']->id,
            'assigned_at' => $now->subHour(),
            'attempts_assign' => 3,
            'last_error' => 'Telephony rejected the assignment payload',
        ]);

        Call::query()->create([
            'phone' => '+77007776655',
            'status' => 'new',
            'attempts_assign' => 0,
        ]);

        OutboxEvent::query()->create([
            'type' => 'call.assigned',
            'aggregate_type' => 'call',
            'aggregate_id' => $assignedPendingDispatchCall->id,
            'payload_json' => [
                'call_id' => $assignedPendingDispatchCall->id,
                'operator_id' => $operators['Chingiz Sadykov']->id,
            ],
            'status' => 'pending',
            'attempts' => 1,
            'next_retry_at' => $now->addMinutes(2),
            'last_error' => 'Telephony timeout on previous attempt',
        ]);

        OutboxEvent::query()->create([
            'type' => 'call.assigned',
            'aggregate_type' => 'call',
            'aggregate_id' => $dispatchedCall->id,
            'payload_json' => [
                'call_id' => $dispatchedCall->id,
                'operator_id' => $operators['Boris Kim']->id,
            ],
            'status' => 'sent',
            'attempts' => 1,
        ]);

        OutboxEvent::query()->create([
            'type' => 'call.assigned',
            'aggregate_type' => 'call',
            'aggregate_id' => $failedDispatchCall->id,
            'payload_json' => [
                'call_id' => $failedDispatchCall->id,
                'operator_id' => $operators['Alice Johnson']->id,
            ],
            'status' => 'failed',
            'attempts' => 5,
            'last_error' => 'Telephony returned 422 Invalid operator state',
        ]);
    }

    private function seedBulkData(CarbonImmutable $now, Generator $faker): void
    {
        $clientCount = $this->resolveCount('CALL_CENTER_CLIENTS', 0);
        $operatorCount = $this->resolveCount('CALL_CENTER_OPERATORS', 0);
        $callCount = $this->resolveCount('CALL_CENTER_CALLS', 0);

        if ($clientCount > 0) {
            $this->insertClients($clientCount, $now);
        }

        if ($operatorCount > 0) {
            $this->insertOperators($operatorCount, $now, $faker);
        }

        if ($callCount > 0) {
            $this->insertCallsAndOutbox($callCount, $now, $faker);
        }
    }

    private function insertClients(int $count, CarbonImmutable $now): void
    {
        $rows = [];

        for ($index = 1; $index <= $count; $index++) {
            $rows[] = [
                'phone' => $this->clientPhone($index),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === self::INSERT_CHUNK_SIZE) {
                Client::query()->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            Client::query()->insert($rows);
        }
    }

    private function insertOperators(int $count, CarbonImmutable $now, Generator $faker): void
    {
        $rows = [];

        for ($index = 1; $index <= $count; $index++) {
            $isActive = $index % 17 !== 0;
            $isAvailable = $isActive && $index % 5 !== 0;

            $rows[] = [
                'name' => sprintf('%s %s %04d', $faker->firstName(), $faker->lastName(), $index),
                'available' => $isAvailable,
                'active' => $isActive,
                'last_call_at' => $isAvailable ? $now->subMinutes(($index % 240) + 1) : $now->subMinutes($index % 30),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === self::INSERT_CHUNK_SIZE) {
                Operator::query()->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            Operator::query()->insert($rows);
        }
    }

    private function insertCallsAndOutbox(int $count, CarbonImmutable $now, Generator $faker): void
    {
        $clientIds = Client::query()->pluck('id')->all();
        $operatorIds = Operator::query()->pluck('id')->all();
        $availableOperatorIds = Operator::query()->where('active', true)->pluck('id')->all();

        if ($clientIds === []) {
            return;
        }

        $callRows = [];
        $callMeta = [];

        for ($index = 1; $index <= $count; $index++) {
            $status = $this->statusForIndex($index);
            $clientId = $clientIds[($index - 1) % count($clientIds)];
            $operatorId = in_array($status, ['assigned', 'dispatched', 'failed'], true)
                ? $availableOperatorIds[($index - 1) % count($availableOperatorIds)]
                : null;
            $phone = $this->phoneForCall($index, $clientIds, $clientId, $faker);
            $assignedAt = $operatorId !== null ? $now->subMinutes(($index % 720) + 1) : null;
            $dispatchedAt = $status === 'dispatched' && $assignedAt !== null
                ? $assignedAt->addMinutes(($index % 5) + 1)
                : null;
            $lastError = match ($status) {
                'failed' => 'Generated failed dispatch sample',
                'assigned' => $index % 4 === 0 ? 'Temporary telephony timeout' : null,
                default => null,
            };

            $callRows[] = [
                'phone' => $phone,
                'status' => $status,
                'client_id' => $operatorId !== null || $index % 3 !== 0 ? $clientId : null,
                'operator_id' => $operatorId,
                'assigned_at' => $assignedAt,
                'dispatched_at' => $dispatchedAt,
                'attempts_assign' => $operatorId !== null ? (($index % 3) + 1) : 0,
                'last_error' => $lastError,
                'created_at' => $now->subMinutes(($index % 1440) + 1),
                'updated_at' => $now,
            ];

            $callMeta[] = [
                'status' => $status,
                'operator_id' => $operatorId,
                'assigned_at' => $assignedAt,
                'last_error' => $lastError,
            ];
        }

        foreach (array_chunk($callRows, self::INSERT_CHUNK_SIZE) as $chunk) {
            Call::query()->insert($chunk);
        }

        $insertedCalls = Call::query()
            ->latest('id')
            ->limit($count)
            ->get(['id'])
            ->reverse()
            ->values();

        $outboxRows = [];

        foreach ($insertedCalls as $offset => $call) {
            $meta = $callMeta[$offset];

            if (! in_array($meta['status'], ['assigned', 'dispatched', 'failed'], true)) {
                continue;
            }

            $outboxStatus = match ($meta['status']) {
                'assigned' => 'pending',
                'dispatched' => 'sent',
                'failed' => 'failed',
            };

            $outboxRows[] = [
                'type' => 'call.assigned',
                'aggregate_type' => 'call',
                'aggregate_id' => $call->id,
                'payload_json' => json_encode([
                    'call_id' => $call->id,
                    'operator_id' => $meta['operator_id'],
                ], JSON_THROW_ON_ERROR),
                'status' => $outboxStatus,
                'attempts' => $outboxStatus === 'failed' ? 5 : 1,
                'next_retry_at' => $outboxStatus === 'pending' ? $now->addMinutes(($offset % 15) + 1) : null,
                'last_error' => $outboxStatus === 'pending' || $outboxStatus === 'failed' ? $meta['last_error'] : null,
                'created_at' => $meta['assigned_at'] ?? $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($outboxRows, self::INSERT_CHUNK_SIZE) as $chunk) {
            DB::table('outbox_events')->insert($chunk);
        }
    }

    private function resolveCount(string $name, int $default): int
    {
        return max(0, (int) env($name, $default));
    }

    private function statusForIndex(int $index): string
    {
        return match (true) {
            $index % 10 === 0 => 'failed',
            $index % 3 === 0 => 'dispatched',
            $index % 2 === 0 => 'assigned',
            default => 'new',
        };
    }

    private function phoneForCall(int $index, array $clientIds, int $clientId, Generator $faker): string
    {
        if ($index % 5 === 0) {
            return $this->generatedPhone($index + count($clientIds) + 500000);
        }

        $clientOffset = array_search($clientId, $clientIds, true);

        if ($clientOffset === false) {
            return $faker->e164PhoneNumber();
        }

        return $this->clientPhone($clientOffset + 1);
    }

    private function clientPhone(int $index): string
    {
        return $this->generatedPhone($index);
    }

    private function generatedPhone(int $index): string
    {
        return sprintf('+7700%07d', $index);
    }

    private function hasDomainData(): bool
    {
        return Client::query()->exists()
            || Operator::query()->exists()
            || Call::query()->exists()
            || OutboxEvent::query()->exists();
    }
}
