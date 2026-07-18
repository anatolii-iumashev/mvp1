<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Call extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone',
        'status',
        'client_id',
        'operator_id',
        'assigned_at',
        'dispatched_at',
        'attempts_assign',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }
}
