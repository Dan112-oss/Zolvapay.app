<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Matches the blueprint's `wallet_balances` table (Section 3): a
     * denormalized cache of ledger_entries, rebuildable from the ledger at
     * any time. If this ever disagrees with the ledger, the ledger wins —
     * this table exists purely so reads (dashboard balance, etc.) don't
     * have to sum the entire ledger on every request.
     *
     * Amounts are BIGINT minor units (cents/kobo) — never floats.
     */
    public function up(): void
    {
        Schema::create('wallet_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->char('currency_code', 3); // ISO 4217: USD, NGN, EUR, KES...
            $table->bigInteger('available_balance')->default(0);
            $table->bigInteger('ledger_balance')->default(0);
            $table->timestamps();

            $table->unique(['wallet_id', 'currency_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_balances');
    }
};
