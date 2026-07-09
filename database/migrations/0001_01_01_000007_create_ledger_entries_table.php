<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Matches the blueprint's `ledger_entries` table (Section 3) — the
     * actual source of truth. Append-only: rows are never updated or
     * deleted, only inserted. Corrections happen via a reversing entry
     * (not built yet), never by editing a row here.
     *
     * No `updated_at` — see LedgerEntry::UPDATED_AT = null. `amount` is
     * always positive; direction comes from `entry_type` (debit/credit).
     *
     * `idempotency_key` is unique per entry, not per transaction: every
     * transfer/credit/debit writes exactly two entries (Section 3's
     * double-entry rule), so WalletService suffixes the caller-supplied
     * key with '#debit' / '#credit' to get two unique values that still
     * trace back to the same client-supplied key.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained('transactions');
            $table->foreignUuid('wallet_id')->constrained('wallets');
            $table->char('currency_code', 3);
            $table->string('entry_type'); // debit | credit
            $table->bigInteger('amount'); // always positive; direction from entry_type
            $table->bigInteger('balance_after'); // snapshot for audit
            $table->string('reference_type'); // transfer, admin_adjustment, fx_conversion, reversal...
            $table->uuid('reference_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'currency_code', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
