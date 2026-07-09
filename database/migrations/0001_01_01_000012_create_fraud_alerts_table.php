<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blueprint Section 2.9 (Fraud/Risk Engine) / Phase 8.
     *
     * A minimal, synchronous stand-in for the event-bus-driven fraud
     * engine the blueprint describes (Kafka consumer, separate service).
     * This runs inline inside the request instead — see FraudService's
     * docblock for why that's a deliberate simplification, not an
     * oversight, and what upgrading it later would look like.
     */
    public function up(): void
    {
        Schema::create('fraud_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('alert_type'); // velocity, large_transaction
            $table->string('severity')->default('info'); // info, warning, blocked
            $table->text('description');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['alert_type', 'severity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fraud_alerts');
    }
};
