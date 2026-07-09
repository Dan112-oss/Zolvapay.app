<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by WalletService when a debit would take a non-system wallet
 * negative. The reserved system wallet is exempt (see WalletService).
 */
class InsufficientBalanceException extends RuntimeException
{
    public static function forWallet(string $walletId, string $currencyCode): self
    {
        return new self("Wallet [{$walletId}] has insufficient {$currencyCode} balance for this operation.");
    }
}
