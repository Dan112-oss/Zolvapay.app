<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blueprint Section 3's own schema specified this table back at
     * Phase 2 ("Audit log (every money-moving action, immutable)") but
     * it was never actually created in any phase through Phase 7 — this
     * closes that gap as part of Phase 8's compliance pass. Append-only,
     * same as ledger_entries and fx_rates: nothing here is ever updated
     * or deleted.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_id')->nullable()->constrained('users'); // null for system-initiated actions
            $table->string('action'); // e.g. 'transfer.created', 'kyc.approved', 'wallet.admin_credit'
            $table->string('entity_type'); // e.g. 'Transaction', 'KycRecord', 'Wallet'
            $table->uuid('entity_id');
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at');

            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
