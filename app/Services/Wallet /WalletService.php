<?php

namespace App\Services\Wallet;

use App\Exceptions\InsufficientBalanceException;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletBalance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The most critical service in the codebase (blueprint Section 6, Phase 2).
 * Every method here creates exactly two ledger_entries rows — one debit,
 * one credit — inside a single DB transaction. Never update a balance
 * directly; always go through here.
 *
 * Concurrency: wallet_balances rows are locked with lockForUpdate() in a
 * deterministic order (sorted by wallet_id) before either balance is
 * changed, so two concurrent requests touching the same pair of wallets
 * can't deadlock each other and can't both read-then-write a stale
 * balance.
 *
 * Idempotency: the caller supplies one idempotency_key per logical
 * operation. Since every operation writes two ledger_entries rows and
 * idempotency_key is unique per *entry* (not per transaction — see the
 * ledger_entries migration), this suffixes the key into '#debit' /
 * '#credit' pairs. A retried request with the same key is detected before
 * any balance changes and returns the original Transaction untouched.
 */
class WalletService
{
    /**
     * Credit a wallet from the system wallet. Originally just an admin
     * test top-up; Phase 5 (PaymentRailService) also calls this for
     * confirmed funding and for reversing a failed withdrawal — $type
     * lets those show up correctly in transaction history instead of as
     * 'admin_adjustment'.
     */
    public function credit(
        Wallet $wallet,
        string $currencyCode,
        int $amountMinor,
        string $idempotencyKey,
        string $referenceType = 'admin_adjustment',
        ?string $referenceId = null,
        string $type = 'admin_adjustment',
    ): Transaction {
        $systemWallet = $this->systemWallet();

        return $this->move(
            debitWallet: $systemWallet,
            creditWallet: $wallet,
            currencyCode: $currencyCode,
            amountMinor: $amountMinor,
            type: $type,
            initiatorWallet: $wallet,
            counterpartyWallet: $systemWallet,
            referenceType: $referenceType,
            referenceId: $referenceId,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Debit a wallet back to the system wallet. Originally just an admin
     * test withdrawal; Phase 5 also calls this to reserve funds the
     * instant a real withdrawal is initiated — see $type's docblock on
     * credit() above.
     */
    public function debit(
        Wallet $wallet,
        string $currencyCode,
        int $amountMinor,
        string $idempotencyKey,
        string $referenceType = 'admin_adjustment',
        ?string $referenceId = null,
        string $type = 'admin_adjustment',
    ): Transaction {
        $systemWallet = $this->systemWallet();

        return $this->move(
            debitWallet: $wallet,
            creditWallet: $systemWallet,
            currencyCode: $currencyCode,
            amountMinor: $amountMinor,
            type: $type,
            initiatorWallet: $wallet,
            counterpartyWallet: $systemWallet,
            referenceType: $referenceType,
            referenceId: $referenceId,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Move money directly between two user wallets in the same currency.
     * (Phase 3 will build the P2P transfer endpoint on top of this —
     * this method is ready for it now.)
     */
    public function transfer(
        Wallet $from,
        Wallet $to,
        string $currencyCode,
        int $amountMinor,
        string $idempotencyKey,
        string $referenceType = 'p2p_transfer',
        ?string $referenceId = null,
    ): Transaction {
        return $this->move(
            debitWallet: $from,
            creditWallet: $to,
            currencyCode: $currencyCode,
            amountMinor: $amountMinor,
            type: 'p2p_transfer',
            initiatorWallet: $from,
            counterpartyWallet: $to,
            referenceType: $referenceType,
            referenceId: $referenceId,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Convert between two currency sub-balances of the SAME wallet
     * (blueprint Section 2.4 / Phase 4). Unlike transfer()/credit()/
     * debit(), the two ledger legs here have different currency codes
     * and different amounts (the debit leg in $fromCurrency, the credit
     * leg in $toCurrency) tied together by one Transaction and one
     * fx_rates row — so this can't reuse move(), which assumes a single
     * shared currency/amount across both legs.
     *
     * $amountMinorFrom/$amountMinorTo and $fxRateId should come directly
     * from an FxRateService::quote() result the caller just fetched, so
     * the amount actually moved always matches the rate on record.
     */
    public function convert(
        Wallet $wallet,
        string $fromCurrency,
        string $toCurrency,
        int $amountMinorFrom,
        int $amountMinorTo,
        string $fxRateId,
        string $idempotencyKey,
    ): Transaction {
        if ($amountMinorFrom <= 0 || $amountMinorTo <= 0) {
            throw new InvalidArgumentException('Amounts must be positive integers of minor units.');
        }

        if ($fromCurrency === $toCurrency) {
            throw new InvalidArgumentException('From and to currency must be different.');
        }

        $debitKey = $idempotencyKey.'#debit';
        $creditKey = $idempotencyKey.'#credit';

        $existing = LedgerEntry::where('idempotency_key', $debitKey)->first();
        if ($existing) {
            return Transaction::findOrFail($existing->transaction_id);
        }

        try {
            return DB::transaction(function () use (
                $wallet, $fromCurrency, $toCurrency, $amountMinorFrom, $amountMinorTo,
                $fxRateId, $debitKey, $creditKey,
            ) {
                // Both legs are the same wallet, different currencies —
                // lock the two wallet_balances rows in a fixed order (by
                // currency code) so a concurrent conversion in the
                // opposite direction (toCurrency -> fromCurrency) for the
                // same wallet can never deadlock against this one.
                $currencies = collect([$fromCurrency, $toCurrency])->sort()->values()->all();

                $locked = [];
                foreach ($currencies as $currencyCode) {
                    $locked[$currencyCode] = $this->lockOrCreateBalance($wallet, $currencyCode);
                }

                $fromBalance = $locked[$fromCurrency];
                $toBalance = $locked[$toCurrency];

                if (! $wallet->is_system && $fromBalance->available_balance < $amountMinorFrom) {
                    throw InsufficientBalanceException::forWallet($wallet->id, $fromCurrency);
                }

                $fromBalance->available_balance -= $amountMinorFrom;
                $fromBalance->ledger_balance -= $amountMinorFrom;
                $fromBalance->save();

                $toBalance->available_balance += $amountMinorTo;
                $toBalance->ledger_balance += $amountMinorTo;
                $toBalance->save();

                $transaction = Transaction::create([
                    'type' => 'fx_conversion',
                    'status' => 'completed',
                    'initiator_wallet_id' => $wallet->id,
                    'counterparty_wallet_id' => null, // no counterparty user — both legs belong to $wallet
                    'amount' => $amountMinorFrom,
                    'currency_code' => $fromCurrency,
                    'fee' => 0,
                    'metadata' => [
                        'to_currency' => $toCurrency,
                        'to_amount_minor' => $amountMinorTo,
                        'fx_rate_id' => $fxRateId,
                    ],
                    'completed_at' => now(),
                ]);

                LedgerEntry::create([
                    'transaction_id' => $transaction->id,
                    'wallet_id' => $wallet->id,
                    'currency_code' => $fromCurrency,
                    'entry_type' => 'debit',
                    'amount' => $amountMinorFrom,
                    'balance_after' => $fromBalance->available_balance,
                    'reference_type' => 'fx_conversion',
                    'reference_id' => $fxRateId,
                    'idempotency_key' => $debitKey,
                ]);

                LedgerEntry::create([
                    'transaction_id' => $transaction->id,
                    'wallet_id' => $wallet->id,
                    'currency_code' => $toCurrency,
                    'entry_type' => 'credit',
                    'amount' => $amountMinorTo,
                    'balance_after' => $toBalance->available_balance,
                    'reference_type' => 'fx_conversion',
                    'reference_id' => $fxRateId,
                    'idempotency_key' => $creditKey,
                ]);

                return $transaction;
            });
        } catch (QueryException $e) {
            $winner = LedgerEntry::where('idempotency_key', $debitKey)->first();
            if ($winner) {
                return Transaction::findOrFail($winner->transaction_id);
            }

            throw $e;
        }
    }

    /**
     * Core double-entry move. Every public method above is a thin wrapper
     * around this with a different (debit, credit) pair and transaction
     * type.
     */
    private function move(
        Wallet $debitWallet,
        Wallet $creditWallet,
        string $currencyCode,
        int $amountMinor,
        string $type,
        Wallet $initiatorWallet,
        Wallet $counterpartyWallet,
        string $referenceType,
        ?string $referenceId,
        string $idempotencyKey,
    ): Transaction {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Amount must be a positive integer of minor units.');
        }

        $debitKey = $idempotencyKey.'#debit';
        $creditKey = $idempotencyKey.'#credit';

        // Idempotency check up front, outside the row locks below — if a
        // previous call with this key already ran, hand back its result
        // instead of touching any balances again. The unique constraint
        // on idempotency_key is the real guarantee; this check is just
        // what makes the common (non-racing) case fast and clean.
        $existing = LedgerEntry::where('idempotency_key', $debitKey)->first();
        if ($existing) {
            return Transaction::findOrFail($existing->transaction_id);
        }

        try {
            return DB::transaction(function () use (
                $debitWallet, $creditWallet, $currencyCode, $amountMinor, $type,
                $initiatorWallet, $counterpartyWallet, $referenceType, $referenceId,
                $debitKey, $creditKey,
            ) {
                // Lock both wallet_balances rows in a fixed order (by wallet
                // id) regardless of which side is debit/credit, so two
                // concurrent transfers between the same two wallets can never
                // deadlock each other.
                $wallets = collect([$debitWallet, $creditWallet])
                    ->sortBy(fn (Wallet $w) => $w->id)
                    ->all();

                $locked = [];
                foreach ($wallets as $wallet) {
                    $locked[$wallet->id] = $this->lockOrCreateBalance($wallet, $currencyCode);
                }

                $debitBalance = $locked[$debitWallet->id];
                $creditBalance = $locked[$creditWallet->id];

                if (! $debitWallet->is_system && $debitBalance->available_balance < $amountMinor) {
                    throw InsufficientBalanceException::forWallet($debitWallet->id, $currencyCode);
                }

                $debitBalance->available_balance -= $amountMinor;
                $debitBalance->ledger_balance -= $amountMinor;
                $debitBalance->save();

                $creditBalance->available_balance += $amountMinor;
                $creditBalance->ledger_balance += $amountMinor;
                $creditBalance->save();

                $transaction = Transaction::create([
                    'type' => $type,
                    'status' => 'completed',
                    'initiator_wallet_id' => $initiatorWallet->id,
                    'counterparty_wallet_id' => $counterpartyWallet->id,
                    'amount' => $amountMinor,
                    'currency_code' => $currencyCode,
                    'fee' => 0,
                    'completed_at' => now(),
                ]);

                LedgerEntry::create([
                    'transaction_id' => $transaction->id,
                    'wallet_id' => $debitWallet->id,
                    'currency_code' => $currencyCode,
                    'entry_type' => 'debit',
                    'amount' => $amountMinor,
                    'balance_after' => $debitBalance->available_balance,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'idempotency_key' => $debitKey,
                ]);

                LedgerEntry::create([
                    'transaction_id' => $transaction->id,
                    'wallet_id' => $creditWallet->id,
                    'currency_code' => $currencyCode,
                    'entry_type' => 'credit',
                    'amount' => $amountMinor,
                    'balance_after' => $creditBalance->available_balance,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'idempotency_key' => $creditKey,
                ]);

                return $transaction;
            });
        } catch (QueryException $e) {
            // Genuine simultaneous race on the same idempotency_key: the
            // unique constraint rejected the loser. Rather than surface a
            // raw 500, check whether it lost to its own duplicate request
            // and return the winner's transaction if so.
            $winner = LedgerEntry::where('idempotency_key', $debitKey)->first();
            if ($winner) {
                return Transaction::findOrFail($winner->transaction_id);
            }

            throw $e;
        }
    }

    /**
     * Lock a wallet_balances row for update, creating it first (at zero)
     * if this is the wallet's first activity in this currency.
     *
     * The create-then-relock fallback handles the narrow race where two
     * requests hit a brand-new (wallet, currency) pair at the same
     * instant — the unique(wallet_id, currency_code) constraint makes the
     * loser's insert fail, and it just re-selects (and locks) the winner's
     * row instead of erroring out.
     */
    private function lockOrCreateBalance(Wallet $wallet, string $currencyCode): WalletBalance
    {
        $balance = WalletBalance::where('wallet_id', $wallet->id)
            ->where('currency_code', $currencyCode)
            ->lockForUpdate()
            ->first();

        if ($balance) {
            return $balance;
        }

        try {
            return WalletBalance::create([
                'wallet_id' => $wallet->id,
                'currency_code' => $currencyCode,
                'available_balance' => 0,
                'ledger_balance' => 0,
            ]);
        } catch (QueryException $e) {
            return WalletBalance::where('wallet_id', $wallet->id)
                ->where('currency_code', $currencyCode)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    /**
     * The single reserved wallet with no owner, used as the other leg of
     * every admin credit/debit. Allowed to go negative — it represents
     * money that (conceptually) came from or left the ledger's external
     * boundary, not a real user's funds.
     */
    public function systemWallet(): Wallet
    {
        return Wallet::firstOrCreate(
            ['is_system' => true],
            ['user_id' => null],
        );
    }
}
