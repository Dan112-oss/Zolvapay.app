<?php

namespace App\Services\Billers;

use App\Exceptions\PaymentRailRejectedException;
use App\Models\BillPayment;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Blueprint Section 2.7 / Phase 7 (Bill Payments).
 *
 * Same reasoning as PaymentRailService::withdrawFunds() (Phase 5): the
 * wallet is debited — reserved — the instant a bill payment is
 * initiated, then reversed only if the biller later reports failure.
 * Re-uses PaymentRailRejectedException rather than introducing a
 * near-identical BillRejectedException for the same "provider rejected
 * this outright" case.
 */
class BillPaymentService
{
    public function __construct(
        private readonly WalletService $walletService,
    ) {
    }

    /**
     * @return Biller[]
     */
    public function listBillers(?string $category = null): array
    {
        return BillerFactory::make()->listBillers($category);
    }

    public function payBill(
        User $user,
        string $billerCode,
        string $billerName,
        string $category,
        string $customerId,
        int $amountMinor,
        string $currencyCode,
        string $idempotencyKey,
    ): BillPayment {
        $wallet = $user->wallet;
        $reference = 'BILL-'.Str::uuid();
        $provider = config('billers.provider', 'mock');

        $billPayment = DB::transaction(function () use (
            $user, $wallet, $billerCode, $billerName, $category, $customerId,
            $amountMinor, $currencyCode, $idempotencyKey, $reference, $provider,
        ) {
            $billPayment = BillPayment::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'provider' => $provider,
                'biller_code' => $billerCode,
                'biller_name' => $billerName,
                'category' => $category,
                'customer_id' => $customerId,
                'reference' => $reference,
                'status' => 'pending',
                'currency_code' => $currencyCode,
                'amount' => $amountMinor,
            ]);

            // Reserve funds first — if the balance is insufficient this
            // throws and the whole transaction (including the row above)
            // rolls back, so the biller is never called.
            $transaction = $this->walletService->debit(
                wallet: $wallet,
                currencyCode: $currencyCode,
                amountMinor: $amountMinor,
                idempotencyKey: $idempotencyKey,
                referenceType: 'bill_payment',
                referenceId: $billPayment->id,
                type: 'bill_payment',
            );

            $billPayment->transaction_id = $transaction->id;
            $billPayment->save();

            return $billPayment;
        });

        // Call the biller outside the DB transaction, same reasoning as
        // PaymentRailService::withdrawFunds().
        $adapter = BillerFactory::make();
        $result = $adapter->payBill($reference, $billerCode, $customerId, $amountMinor, $currencyCode);

        $billPayment->provider_reference = $result->providerReference;
        $billPayment->metadata = ['initiation_response' => $result->raw];

        if ($result->isFailed()) {
            $reversal = $this->walletService->credit(
                wallet: $wallet,
                currencyCode: $currencyCode,
                amountMinor: $amountMinor,
                idempotencyKey: $reference.'#reversal',
                referenceType: 'bill_payment_reversal',
                referenceId: $billPayment->id,
                type: 'bill_payment',
            );

            $billPayment->status = 'failed';
            $billPayment->metadata = array_merge($billPayment->metadata, ['reversal_transaction_id' => $reversal->id]);
            $billPayment->save();

            throw PaymentRailRejectedException::forReason($result->message ?? 'bill payment rejected');
        }

        $billPayment->status = $result->isSuccessful() ? 'successful' : 'processing';
        $billPayment->save();

        return $billPayment->fresh();
    }

    /**
     * Apply a confirmed webhook event. Idempotent, same pattern as
     * PaymentRailService::handleWebhookEvent().
     */
    public function handleWebhookEvent(BillWebhookEvent $event): void
    {
        if (! $event->reference) {
            return;
        }

        $billPayment = BillPayment::where('reference', $event->reference)->first();

        if (! $billPayment || in_array($billPayment->status, ['successful', 'failed'], true)) {
            return;
        }

        $billPayment->provider_reference = $billPayment->provider_reference ?? $event->providerReference;
        $billPayment->metadata = array_merge($billPayment->metadata ?? [], ['webhook_payload' => $event->raw]);

        if ($event->status === 'successful') {
            $billPayment->status = 'successful';
            $billPayment->save();

            return;
        }

        if ($event->status === 'failed') {
            $wallet = $billPayment->wallet;

            $reversal = $this->walletService->credit(
                wallet: $wallet,
                currencyCode: $billPayment->currency_code,
                amountMinor: $billPayment->amount,
                idempotencyKey: $billPayment->reference.'#reversal',
                referenceType: 'bill_payment_reversal',
                referenceId: $billPayment->id,
                type: 'bill_payment',
            );

            $billPayment->status = 'failed';
            $billPayment->metadata = array_merge($billPayment->metadata, ['reversal_transaction_id' => $reversal->id]);
            $billPayment->save();

            return;
        }

        $billPayment->save();
    }
}
