<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Operator extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'available', 'active', 'last_call_at'];

    protected function casts(): array
    {
        return [
            'available' => 'boolean',
            'active' => 'boolean',
            'last_call_at' => 'datetime',
        ];
    }
}
