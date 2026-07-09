<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Matches the blueprint's `kyc_records` table (Section 3), extended
     * with what a real Tier 1 flow needs in practice: file paths for the
     * uploaded documents (stored on a private disk, never `public/`), a
     * rejection reason, and who/when reviewed it.
     *
     * document_number_hash: the raw ID number is hashed before it ever
     * reaches this table — never store it unhashed (blueprint Sections 3
     * and 5). It exists for audit/dedup purposes only; admins review the
     * uploaded document images themselves, not this hash.
     */
    public function up(): void
    {
        Schema::create('kyc_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('tier')->default(1);
            $table->string('document_type'); // passport, national_id, drivers_license
            $table->string('document_number_hash');
            $table->string('document_front_path');
            $table->string('document_back_path')->nullable();
            $table->string('selfie_path')->nullable();
            $table->string('verification_status')->default('pending'); // pending, approved, rejected
            $table->string('provider')->default('mock'); // which KycProvider handled this submission
            $table->text('rejection_reason')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'verification_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kyc_records');
    }
};
