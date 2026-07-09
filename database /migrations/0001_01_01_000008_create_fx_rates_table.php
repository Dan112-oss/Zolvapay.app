<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Matches the blueprint's `fx_rates` table (Section 3): a historical,
     * append-only record of every rate the app has quoted or used. Rows
     * are never updated — a refresh (RefreshFxRates command) or a live
     * quote (FxRateService::quote()) always inserts a new row, so a
     * completed fx_conversion transaction can always point back at the
     * exact rate/margin it used for audit, even after the market rate
     * has since moved.
     *
     * mid_rate is the true market rate from the provider; effective_rate
     * is mid_rate with margin_bps applied — the rate actually given to
     * the user. Both are stored so support/compliance can see the spread
     * on any historical conversion.
     */
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('mid_rate', 18, 8);
            $table->integer('margin_bps');
            $table->decimal('effective_rate', 18, 8);
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['base_currency', 'quote_currency', 'fetched_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};
