<?php

namespace App\Services\AirportSync;

use RuntimeException;

class AirportSyncConflictException extends RuntimeException
{
    /**
     * @param  array<int, array{type: string, message: string}>  $conflicts
     */
    public function __construct(string $message, public readonly array $conflicts = [])
    {
        parent::__construct($message . ($conflicts ? ' ' . collect($conflicts)->pluck('message')->take(5)->implode(' ') : ''));
    }
}
