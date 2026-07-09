<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by PaymentRailService when the rail itself rejects a funding or
 * withdrawal request outright (e.g. an invalid bank account), as opposed
 * to it succeeding-but-pending or failing later via webhook. Distinct
 * from InsufficientBalanceException, which is ZolvaPay's own ledger
 * check, not the rail's.
 */
class PaymentRailRejectedException extends RuntimeException
{
    public static function forReason(string $reason): self
    {
        return new self("Payment rail rejected the request: {$reason}");
    }
}
