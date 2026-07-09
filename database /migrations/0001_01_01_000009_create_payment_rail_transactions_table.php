<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blueprint Section 2.5 / Phase 5 (External Transfers & Withdrawals).
     *
     * One row per funding or withdrawal attempt against a payment rail
     * (Flutterwave, mock, etc). This is deliberately separate from
     * `transactions` — a payment_rail_transactions row can exist in
     * 'pending' status before any money has moved on the ledger at all
     * (e.g. a funding charge the user hasn't completed yet), whereas
     * every `transactions` row is already a completed ledger movement.
     *
     * `reference` is the tx_ref *we* generate and send to the rail —
     * it's what webhooks echo back so we can find this row again.
     * `rail_reference` is the rail's own id for the charge/transfer,
     * filled in once the rail responds/webhooks.
     */
    public function up(): void
    {
        Schema::create('payment_rail_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('wallet_id')->constrained('wallets');
            // Nullable: only set once a ledger Transaction actually exists
            // for this attempt (immediately for a withdrawal, once
            // confirmed for a funding).
            $table->foreignUuid('transaction_id')->nullable()->constrained('transactions');
            $table->string('direction'); // funding, withdrawal
            $table->string('rail'); // mock, flutterwave, ...
            $table->string('reference')->unique();
            $table->string('rail_reference')->nullable();
            $table->string('status')->default('pending'); // pending, processing, successful, failed
            $table->char('currency_code', 3);
            $table->bigInteger('amount');
            $table->bigInteger('fee')->default(0);
            // Payer/bank details the user submitted, plus the raw
            // request/response/webhook payloads from the rail — kept
            // together here rather than across several nullable columns.
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['rail', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_rail_transactions');
    }
};
