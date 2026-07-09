<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blueprint Section 2.8 (Savings & Loans Service) / Phase 9:
     * "Micro-loan scoring engine (rules-based initially, ML-based later
     * using transaction history)." LoanScoringService is the rules-based
     * engine this schema supports; nothing here precludes swapping in an
     * ML-based scorer later — score()'s return shape is all any consumer
     * of it needs to stay stable.
     *
     * interest_rate_bps and outstanding_balance_minor use SIMPLE
     * interest computed once at disbursement (principal + principal *
     * rate), not compounding — deliberately the simplest correct model
     * for a first micro-loan product. A compounding/amortizing schedule
     * is a real follow-up, not a bug.
     */
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('wallet_id')->constrained('wallets');
            $table->char('currency_code', 3);
            $table->bigInteger('principal_minor');
            $table->integer('interest_rate_bps');
            $table->bigInteger('outstanding_balance_minor'); // principal + interest, decreases as repaid
            $table->string('status')->default('pending'); // pending, rejected, active, repaid, defaulted
            $table->text('rejection_reason')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->date('due_date')->nullable();
            $table->json('metadata')->nullable(); // scoring inputs/output, for audit
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
