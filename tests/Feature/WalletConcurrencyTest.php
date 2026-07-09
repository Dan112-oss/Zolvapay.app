<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientBalanceException;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PREREQUISITES this repo doesn't have yet, as of Phase 7: a
 * database/factories/UserFactory.php and WalletFactory.php (standard
 * Laravel scaffolding — `php artisan make:factory`), a phpunit.xml, and
 * bootstrap/app.php. None of Phases 4–8's deliverables have touched
 * those since they were outside every phase's stated scope — this test
 * assumes they exist by the time it's run, matching a normal Laravel
 * project layout.
 *
 * Blueprint Phase 8 deliverable: "Load testing the ledger specifically
 * (concurrent transfer stress test)."
 *
 * IMPORTANT — read before trusting this as your whole answer to that
 * deliverable: PHPUnit runs in a single PHP process. It CANNOT create
 * two genuinely simultaneous database transactions the way two real
 * concurrent HTTP requests would — every assertion below is sequential
 * under the hood, even where it's simulating a race. What this test DOES
 * verify, correctly: idempotency keys prevent a retried request from
 * double-crediting/debiting, and an insufficient balance is rejected
 * cleanly rather than silently going negative.
 *
 * What it does NOT verify: that WalletService's lockForUpdate() row
 * locking actually serializes two truly concurrent debits against the
 * same wallet_balances row under real network/DB latency. Proving that
 * requires real concurrent load — see load-test/k6-ledger-load-test.js
 * alongside this file, which fires genuinely parallel HTTP requests at
 * a running instance of the app. Run that against staging before launch;
 * this PHPUnit file is a fast regression check, not a substitute for it.
 */
class WalletConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_idempotency_key_prevents_double_debit(): void
    {
        [$user, $wallet, $walletService] = $this->fundedUser(10000); // 100.00

        $key = 'test-idempotency-key';

        $first = $walletService->debit($wallet, 'NGN', 5000, $key, 'test', null, 'admin_adjustment');
        $second = $walletService->debit($wallet, 'NGN', 5000, $key, 'test', null, 'admin_adjustment');

        $this->assertSame($first->id, $second->id, 'Retrying the same idempotency key must return the SAME transaction, not create a new debit.');

        $balance = $wallet->balances()->where('currency_code', 'NGN')->first();
        $this->assertSame(5000, $balance->available_balance, 'Balance must reflect exactly ONE debit of 5000, not two.');
    }

    public function test_debit_beyond_balance_is_rejected_and_balance_is_unchanged(): void
    {
        [$user, $wallet, $walletService] = $this->fundedUser(1000); // 10.00

        $this->expectException(InsufficientBalanceException::class);

        try {
            $walletService->debit($wallet, 'NGN', 5000, 'over-limit-key', 'test', null, 'admin_adjustment');
        } finally {
            $balance = $wallet->balances()->where('currency_code', 'NGN')->first();
            $this->assertSame(1000, $balance->available_balance, 'A failed debit must never partially apply — balance must be untouched.');
        }
    }

    public function test_conversion_debits_and_credits_different_currencies_correctly(): void
    {
        [$user, $wallet, $walletService] = $this->fundedUser(10000, 'USD'); // 100.00 USD

        $transaction = $walletService->convert(
            wallet: $wallet,
            fromCurrency: 'USD',
            toCurrency: 'NGN',
            amountMinorFrom: 5000, // 50.00 USD
            amountMinorTo: 7750000, // arbitrary test rate: 50 USD -> 77,500 NGN
            fxRateId: (string) \Illuminate\Support\Str::uuid(),
            idempotencyKey: 'test-convert-key',
        );

        $usdBalance = $wallet->balances()->where('currency_code', 'USD')->first();
        $ngnBalance = $wallet->balances()->where('currency_code', 'NGN')->first();

        $this->assertSame(5000, $usdBalance->available_balance, 'USD balance must be debited by exactly the from-amount.');
        $this->assertSame(7750000, $ngnBalance->available_balance, 'NGN balance must be credited by exactly the to-amount.');
        $this->assertSame('fx_conversion', $transaction->type);
    }

    /**
     * @return array{0: User, 1: Wallet, 2: WalletService}
     */
    private function fundedUser(int $amountMinor, string $currencyCode = 'NGN'): array
    {
        $user = User::factory()->create(['kyc_tier' => 1]);
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);
        $walletService = app(WalletService::class);

        $walletService->credit($wallet, $currencyCode, $amountMinor, 'seed-'.$wallet->id, 'test', null, 'admin_adjustment');

        return [$user, $wallet, $walletService];
    }
}
