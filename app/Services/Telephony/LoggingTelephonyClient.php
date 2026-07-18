<?php

namespace App\Services\Telephony;

use App\Contracts\TelephonyClient;
use Illuminate\Support\Facades\Log;

class LoggingTelephonyClient implements TelephonyClient
{
    public function sendCallAssigned(int $callId, int $operatorId, string $idempotencyKey): void
    {
        Log::info('telephony.call_assigned', [
            'call_id' => $callId,
            'operator_id' => $operatorId,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
