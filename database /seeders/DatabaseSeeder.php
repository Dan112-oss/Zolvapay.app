<?php

namespace Database\Seeders;

use App\Models\Wallet;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Only the system wallet is seeded here — WalletService's
     * systemWallet() lazily creates one if missing, so this isn't
     * strictly required, but seeding it explicitly means the very first
     * credit/debit/transfer in a fresh environment doesn't pay that
     * creation cost mid-request.
     */
    public function run(): void
    {
        Wallet::firstOrCreate(
            ['is_system' => true],
            ['user_id' => null],
        );
    }
}
