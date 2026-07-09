<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by FraudService::checkVelocity() when a user has exceeded a
 * configured action threshold within its time window.
 */
class FraudCheckFailedException extends RuntimeException
{
    public static function velocityExceeded(string $action, int $limit, int $windowSeconds): self
    {
        return new self(
            "Too many {$action} attempts. Limit is {$limit} per {$windowSeconds}s — please wait before trying again."
        );
    }
}
