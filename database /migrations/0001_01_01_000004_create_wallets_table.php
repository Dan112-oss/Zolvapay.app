<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Matches the blueprint's `wallets` table (Section 3): one wallet per
     * user, capable of holding balances in multiple currencies via
     * wallet_balances.
     *
     * `is_system` marks the single reserved wallet used as the "outside
     * the ledger" counterparty for admin credits/debits until a real
     * funding source (Phase 5 payment rails) exists — see
     * WalletService::systemWallet(). It's the only wallet allowed to go
     * negative.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
