<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blueprint Section 2.8 (Savings & Loans Service) / Phase 9.
     *
     * Design note — read before touching SavingsService: a goal is NOT
     * its own wallet/currency sub-balance. Every user has exactly one
     * Wallet (established at signup in Phase 1, and relied on by every
     * controller since as $user->wallet — a hasOne, not hasMany).
     * Changing that to support one wallet per goal would be a much
     * larger, riskier refactor than this phase's scope justifies.
     *
     * Instead, "locking" money into a goal DEBITS the user's normal
     * currency balance (a real ledger entry, via WalletService::debit())
     * and current_amount_minor here is the bookkeeping record of how
     * much is earmarked. Withdrawing CREDITS it back. This means the
     * money is genuinely unavailable for P2P/withdrawal/bills while
     * "in" a goal — it actually left the spendable balance — while still
     * only requiring ONE wallet per user. See SavingsService's docblock
     * for the one deliberate simplification this creates (interest
     * accrual doesn't post a ledger entry until withdrawal).
     */
    public function up(): void
    {
        Schema::create('savings_goals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('wallet_id')->constrained('wallets');
            $table->string('name');
            $table->char('currency_code', 3);
            $table->bigInteger('target_amount_minor')->nullable(); // nullable: open-ended saving is allowed
            $table->bigInteger('current_amount_minor')->default(0);
            $table->integer('interest_rate_bps')->default(0); // annual, simple (not compounded) — see SavingsService
            $table->date('target_date')->nullable();
            $table->string('status')->default('active'); // active, completed, closed
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('savings_goals');
    }
};
