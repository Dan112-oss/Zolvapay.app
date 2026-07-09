<?php

namespace App\Jobs;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Placeholder for the real Notification Service (blueprint Section 2.10,
 * Phase 10). Dispatched via the 'redis' queue connection so
 * WalletService::transfer() never blocks the request waiting on a
 * push/SMS/email send — matches the blueprint's "never send
 * synchronously in the payment path" rule (Section 2.10).
 *
 * Swap the body of handle() for real push/SMS/email dispatch once a
 * notification vendor is wired up; until then this just logs, the same
 * way MockKycProvider (Phase 1) stands in for a real KYC vendor.
 */
class SendTransferNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $transactionId)
    {
    }

    public function handle(): void
    {
        $transaction = Transaction::with(['initiatorWallet.user', 'counterpartyWallet.user'])
            ->find($this->transactionId);

        if (! $transaction) {
            // Nothing to notify about — the transaction may have been
            // looked up before the DB transaction committed on a replica,
            // or the id was stale. Either way, don't retry indefinitely.
            return;
        }

        $sender = $transaction->initiatorWallet?->user;
        $recipient = $transaction->counterpartyWallet?->user;

        Log::info('Transfer notification (stand-in for push/SMS/email)', [
            'transaction_id' => $transaction->id,
            'type' => $transaction->type,
            'amount_minor' => $transaction->amount,
            'currency_code' => $transaction->currency_code,
            'sender_user_id' => $sender?->id,
            'sender_name' => $sender?->name,
            'recipient_user_id' => $recipient?->id,
            'recipient_name' => $recipient?->name,
        ]);
    }
}
