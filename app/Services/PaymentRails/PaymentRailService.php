<?php

namespace App\Services\PaymentRails;

use App\Exceptions\PaymentRailRejectedException;
use App\Models\PaymentRailTransaction;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Blueprint Phase 5 (External Transfers & Withdrawals).
 *
 * The one rule that shapes this whole class: WalletService is the ONLY
 * thing that ever writes to a balance. Everything here is about deciding
 * *when* to call it relative to what the rail says:
 *
 *  - Funding: money hasn't arrived yet at initiation, so the wallet is
 *    only credited once a webhook (or, for the mock rail, the initiation
 *    call itself) confirms success.
 *  - Withdrawal: we debit — reserve — the wallet immediately at
 *    initiation, then reverse (credit back) only if the rail later
 *    reports failure. This mirrors how real card/bank holds work and
 *    means a user can never see a withdrawal "pending" while also still
 *    being able to spend that money elsewhere.
 */
class PaymentRailService
{
    public function __construct(
        private readonly WalletService $walletService,
    ) {
    }

    /**
     * Start a wallet top-up. Returns the PaymentRailTransaction row;
     * check ->status and the returned checkout URL (if any) to know
     * what the caller should do next.
     */
    public function fundWallet(
        User $user,
        string $currencyCode,
        int $amountMinor,
        array $payerDetails,
        string $idempotencyKey,
    ): PaymentRailTransaction {
        $wallet = $user->wallet;
        $reference = 'FUND-'.Str::uuid();
        $adapter = PaymentRailFactory::forCurrency($currencyCode);
        $railName = $this->railNameFor($currencyCode);

        $railTxn = PaymentRailTransaction::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'direction' => 'funding',
            'rail' => $railName,
            'reference' => $reference,
            'status' => 'pending',
            'currency_code' => $currencyCode,
            'amount' => $amountMinor,
            'metadata' => ['payer_details' => $payerDetails],
        ]);

        $result = $adapter->initiateFunding($reference, $amountMinor, $currencyCode, $payerDetails);

        $railTxn->rail_reference = $result->railReference;
        $railTxn->metadata = array_merge($railTxn->metadata ?? [], [
            'checkout_url' => $result->checkoutUrl,
            'initiation_response' => $result->raw,
        ]);

        if ($result->isFailed()) {
            $railTxn->status = 'failed';
            $railTxn->save();

            throw PaymentRailRejectedException::forReason($result->message ?? 'funding request rejected');
        }

        if ($result->isSuccessful()) {
            // Adapter resolved synchronously (e.g. the mock rail) —
            // finalize now rather than waiting for a webhook that will
            // never come.
            $this->creditWalletForFunding($railTxn, $idempotencyKey);
        } else {
            $railTxn->status = 'processing';
            $railTxn->save();
        }

        return $railTxn->fresh();
    }

    /**
     * Start a withdrawal to an external bank account. The wallet is
     * already debited by the time this returns successfully — see the
     * class docblock for why. If the rail rejects the request outright,
     * that debit is reversed before this throws.
     */
    public function withdrawFunds(
        User $user,
        string $currencyCode,
        int $amountMinor,
        array $bankDetails,
        string $idempotencyKey,
    ): PaymentRailTransaction {
        $wallet = $user->wallet;
        $reference = 'WDRL-'.Str::uuid();
        $railName = $this->railNameFor($currencyCode);

        // Step 1: reserve the funds. If the balance is insufficient this
        // throws InsufficientBalanceException and the whole DB
        // transaction (including the railTxn row) rolls back — the rail
        // is never called.
        $railTxn = DB::transaction(function () use (
            $user, $wallet, $currencyCode, $amountMinor, $bankDetails, $idempotencyKey, $reference, $railName,
        ) {
            $railTxn = PaymentRailTransaction::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'direction' => 'withdrawal',
                'rail' => $railName,
                'reference' => $reference,
                'status' => 'pending',
                'currency_code' => $currencyCode,
                'amount' => $amountMinor,
                'metadata' => ['bank_details' => $bankDetails],
            ]);

            $transaction = $this->walletService->debit(
                wallet: $wallet,
                currencyCode: $currencyCode,
                amountMinor: $amountMinor,
                idempotencyKey: $idempotencyKey,
                referenceType: 'withdrawal',
                referenceId: $railTxn->id,
                type: 'external_transfer',
            );

            $railTxn->transaction_id = $transaction->id;
            $railTxn->save();

            return $railTxn;
        });

        // Step 2: now that the funds are durably reserved, actually call
        // the rail. This intentionally happens outside the DB
        // transaction above — an external HTTP call has no business
        // holding a database transaction open.
        $adapter = PaymentRailFactory::forRail($railName);
        $result = $adapter->initiateWithdrawal($reference, $amountMinor, $currencyCode, $bankDetails);

        $railTxn->rail_reference = $result->railReference;
        $railTxn->metadata = array_merge($railTxn->metadata ?? [], ['initiation_response' => $result->raw]);

        if ($result->isFailed()) {
            // The rail rejected it outright (e.g. bad account number) —
            // release the hold immediately rather than waiting on a
            // webhook that will never arrive for a request that was
            // never accepted.
            $reversal = $this->walletService->credit(
                wallet: $wallet,
                currencyCode: $currencyCode,
                amountMinor: $amountMinor,
                idempotencyKey: $reference.'#reversal',
                referenceType: 'withdrawal_reversal',
                referenceId: $railTxn->id,
                type: 'external_transfer',
            );

            $railTxn->status = 'failed';
            $railTxn->metadata = array_merge($railTxn->metadata, ['reversal_transaction_id' => $reversal->id]);
            $railTxn->save();

            throw PaymentRailRejectedException::forReason($result->message ?? 'withdrawal request rejected');
        }

        // 'successful' (mock — nothing more to do, the debit already
        // stands) or 'pending' (real rail — a webhook will finalize this
        // later via applyWithdrawalWebhook()).
        $railTxn->status = $result->isSuccessful() ? 'successful' : 'processing';
        $railTxn->save();

        return $railTxn->fresh();
    }

    /**
     * Apply a confirmed webhook event from any rail. Idempotent — a
     * duplicate webhook for a payment_rail_transactions row already in a
     * terminal status is a no-op.
     */
    public function handleWebhookEvent(string $railName, PaymentRailWebhookEvent $event): void
    {
        if (! $event->reference) {
            return; // nothing to match this event against
        }

        $railTxn = PaymentRailTransaction::where('reference', $event->reference)->first();

        if (! $railTxn || in_array($railTxn->status, ['successful', 'failed'], true)) {
            return; // unknown reference, or already resolved — ignore
        }

        $railTxn->rail_reference = $railTxn->rail_reference ?? $event->railTransactionId;
        $railTxn->metadata = array_merge($railTxn->metadata ?? [], ['webhook_payload' => $event->raw]);

        if ($railTxn->direction === 'funding') {
            $this->applyFundingWebhook($railTxn, $event);
        } else {
            $this->applyWithdrawalWebhook($railTxn, $event);
        }
    }

    private function applyFundingWebhook(PaymentRailTransaction $railTxn, PaymentRailWebhookEvent $event): void
    {
        if ($event->status === 'successful') {
            $this->creditWalletForFunding($railTxn, $railTxn->reference.'#webhook');
        } elseif ($event->status === 'failed') {
            $railTxn->status = 'failed';
            $railTxn->save();
        } else {
            $railTxn->save(); // still pending — persist the metadata merge above
        }
    }

    private function applyWithdrawalWebhook(PaymentRailTransaction $railTxn, PaymentRailWebhookEvent $event): void
    {
        if ($event->status === 'successful') {
            $railTxn->status = 'successful';
            $railTxn->save();

            return;
        }

        if ($event->status === 'failed') {
            // The rail confirmed the payout never landed — release the
            // hold we placed at initiation.
            $wallet = $railTxn->wallet;

            $reversal = $this->walletService->credit(
                wallet: $wallet,
                currencyCode: $railTxn->currency_code,
                amountMinor: $railTxn->amount,
                idempotencyKey: $railTxn->reference.'#reversal',
                referenceType: 'withdrawal_reversal',
                referenceId: $railTxn->id,
                type: 'external_transfer',
            );

            $railTxn->status = 'failed';
            $railTxn->metadata = array_merge($railTxn->metadata ?? [], ['reversal_transaction_id' => $reversal->id]);
            $railTxn->save();

            return;
        }

        $railTxn->save();
    }

    private function creditWalletForFunding(PaymentRailTransaction $railTxn, string $idempotencyKey): void
    {
        $wallet = $railTxn->wallet;

        $transaction = $this->walletService->credit(
            wallet: $wallet,
            currencyCode: $railTxn->currency_code,
            amountMinor: $railTxn->amount,
            idempotencyKey: $idempotencyKey,
            referenceType: 'funding',
            referenceId: $railTxn->id,
            type: 'external_transfer',
        );

        $railTxn->status = 'successful';
        $railTxn->transaction_id = $transaction->id;
        $railTxn->save();
    }

    private function railNameFor(string $currencyCode): string
    {
        return config("payment_rails.currency_rail_map.{$currencyCode}")
            ?? config('payment_rails.default', 'mock');
    }
}
