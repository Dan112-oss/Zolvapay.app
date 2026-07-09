<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Matches the blueprint's `transactions` table (Section 3): the
     * human-facing summary of a group of ledger_entries. Created before
     * ledger_entries in this migration order so ledger_entries can carry
     * a real foreign key back to it.
     *
     * 'admin_adjustment' is added to `type` beyond the blueprint's list —
     * it's what WalletService::credit()/debit() use for the admin
     * test top-up/withdraw flow that stands in for a real funding source
     * until Phase 5.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type'); // p2p_transfer, external_transfer, fx_conversion, bill_payment, card_transaction, loan_repayment, admin_adjustment
            $table->string('status')->default('pending'); // pending, completed, failed, reversed
            $table->foreignUuid('initiator_wallet_id')->constrained('wallets');
            $table->foreignUuid('counterparty_wallet_id')->nullable()->constrained('wallets');
            $table->bigInteger('amount');
            $table->char('currency_code', 3);
            $table->bigInteger('fee')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['initiator_wallet_id', 'created_at']);
            $table->index(['counterparty_wallet_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
