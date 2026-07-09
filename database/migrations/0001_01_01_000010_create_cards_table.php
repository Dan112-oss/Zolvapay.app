<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blueprint Section 2.6 (Card Issuing Service) + Section 5's PCI-DSS
     * rule: "card data never touches your core DB directly — tokenize
     * via processor." This table only ever stores what's safe to store —
     * a masked PAN, last 4 digits, expiry, and the processor's own
     * opaque card token. Full PAN/CVV are fetched on demand straight
     * from the processor (CardService::reveal()) and are never written
     * here.
     */
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('wallet_id')->constrained('wallets');
            // Which wallet currency sub-balance this card draws from.
            // Real-time spend authorization against that balance is a
            // follow-up phase (see CardService's docblock) — this phase
            // covers issuance and management only.
            $table->char('currency_code', 3);
            $table->string('processor'); // mock, marqeta
            $table->string('processor_card_id')->nullable();
            $table->string('masked_pan'); // e.g. "•••• •••• •••• 4242"
            $table->char('last_four', 4);
            $table->unsignedTinyInteger('expiry_month');
            $table->unsignedSmallInteger('expiry_year');
            $table->string('cardholder_name');
            $table->string('card_type')->default('virtual'); // virtual (physical is a future phase)
            $table->string('status')->default('active'); // active, frozen, closed
            $table->bigInteger('spend_limit_minor')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
