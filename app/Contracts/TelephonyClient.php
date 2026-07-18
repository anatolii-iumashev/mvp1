<?php

namespace App\Contracts;

interface TelephonyClient
{
    public function sendCallAssigned(int $callId, int $operatorId, string $idempotencyKey): void;
}
