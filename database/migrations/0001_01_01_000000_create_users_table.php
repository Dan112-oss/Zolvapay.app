<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * NOTE: If this project was scaffolded with `laravel new`, this file
     * replaces the default `0001_01_01_000000_create_users_table.php`
     * migration Laravel ships with. Don't run both — keep this one, since
     * it matches the ZolvaPay blueprint's users schema (Section 3).
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name'); // Not in the original blueprint table,
            // but required for anything user-facing (dashboard greeting,
            // card name, etc.) — added here deliberately.
            $table->string('email')->unique();
            $table->string('phone')->unique()->nullable();
            $table->string('country_code', 2)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->unsignedTinyInteger('kyc_tier')->default(0);
            $table->string('status')->default('active'); // active, frozen, closed
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
