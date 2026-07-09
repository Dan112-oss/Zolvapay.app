<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blueprint Section 2.7 (Bill Payments Service) / Phase 7.
     *
     * Same shape and reasoning as payment_rail_transactions (Phase 5):
     * one row per bill payment attempt, tracked separately from
     * `transactions` because a row here can exist in 'pending' status
     * before any ledger movement happens at all. `customer_id` is
     * whatever identifier the biller needs to route the payment (a
     * phone number for airtime, a meter number for electricity, etc.)
     * — deliberately untyped/free-text since it varies per biller.
     */
    public function up(): void
    {
        Schema::create('bill_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('wallet_id')->constrained('wallets');
            $table->foreignUuid('transaction_id')->nullable()->constrained('transactions');
            $table->string('provider'); // mock, flutterwave
            $table->string('biller_code');
            $table->string('biller_name');
            $table->string('category'); // airtime, electricity, tv, ...
            $table->string('customer_id'); // phone number, meter number, smartcard number, etc.
            $table->string('reference')->unique();
            $table->string('provider_reference')->nullable();
            $table->string('status')->default('pending'); // pending, processing, successful, failed
            $table->char('currency_code', 3);
            $table->bigInteger('amount');
            $table->bigInteger('fee')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['provider', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bill_payments');
    }
};
