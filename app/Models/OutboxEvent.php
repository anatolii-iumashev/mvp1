<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'aggregate_type',
        'aggregate_id',
        'payload_json',
        'status',
        'attempts',
        'next_retry_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'next_retry_at' => 'datetime',
        ];
    }

    public static function createUnique(string $type, string $aggregateType, int $aggregateId, array $payload): self
    {
        return static::query()->firstOrCreate(
            [
                'type' => $type,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
            ],
            [
                'payload_json' => $payload,
                'status' => 'pending',
                'attempts' => 0,
            ],
        );
    }
}
