<?php

namespace App\Services\Savings;

use App\Exceptions\InsufficientBalanceException;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Services\Wallet\WalletService;
use InvalidArgumentException;

/**
 * Blueprint Section 2.8 / Phase 9 (Savings & Loans). See the
 * savings_goals migration's docblock for why a goal isn't its own
 * wallet — this class is what actually enforces that design.
 *
 * INTEREST ACCRUAL SIMPLIFICATION, stated plainly: accrueInterest()
 * increases current_amount_minor directly WITHOUT posting a
 * ledger_entries row — because a goal isn't a wallet balance, there's no
 * real-money account to post it to yet. That interest becomes REAL
 * money — with a proper double-entry ledger record, sourced from the
 * system wallet — only when withdraw() later pays it out. Day-to-day,
 * this means a goal's current_amount_minor is not, by itself, fully
 * reconstructable from ledger_entries the way every other balance in
 * this app is (Section 3's own rule). That's a real, deliberate gap in
 * this phase's audit trail — worth closing (e.g. a daily
 * 'savings_interest_accrual' ledger entry against the system wallet,
 * even though it doesn't touch the user's spendable balance) before
 * this handles real user money at scale.
 */
class SavingsService
{
    public function __construct(
        private readonly WalletService $walletService,
    ) {
    }

    public function createGoal(
        User $user,
        string $name,
        string $currencyCode,
        ?int $targetAmountMinor,
        ?string $targetDate,
        int $interestRateBps,
    ): SavingsGoal {
        return SavingsGoal::create([
            'user_id' => $user->id,
            'wallet_id' => $user->wallet->id,
            'name' => $name,
            'currency_code' => $currencyCode,
            'target_amount_minor' => $targetAmountMinor,
            'current_amount_minor' => 0,
            'interest_rate_bps' => $interestRateBps,
            'target_date' => $targetDate,
            'status' => 'active',
        ]);
    }

    /**
     * Moves money FROM the user's spendable balance INTO the goal — a
     * real debit, real ledger entry, via WalletService.
     */
    public function deposit(SavingsGoal $goal, int $amountMinor, string $idempotencyKey): SavingsGoal
    {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Deposit amount must be positive.');
        }
        if ($goal->status !== 'active') {
            throw new InvalidArgumentException('Cannot deposit into a goal that is not active.');
        }

        // Let InsufficientBalanceException propagate — the goal is only
        // updated below if the debit actually succeeds.
        $this->walletService->debit(
            wallet: $goal->wallet,
            currencyCode: $goal->currency_code,
            amountMinor: $amountMinor,
            idempotencyKey: $idempotencyKey,
            referenceType: 'savings_deposit',
            referenceId: $goal->id,
            type: 'savings_deposit',
        );

        $goal->current_amount_minor += $amountMinor;
        if ($goal->target_amount_minor && $goal->current_amount_minor >= $goal->target_amount_minor) {
            $goal->status = 'completed';
        }
        $goal->save();

        return $goal;
    }

    /**
     * Moves money FROM the goal BACK INTO the user's spendable balance —
     * a real credit, real ledger entry. $amountMinor may include accrued
     * interest that was only ever bookkeeping until this exact call —
     * see the class docblock.
     */
    public function withdraw(SavingsGoal $goal, int $amountMinor, string $idempotencyKey): SavingsGoal
    {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Withdrawal amount must be positive.');
        }
        if ($amountMinor > $goal->current_amount_minor) {
            throw new InvalidArgumentException('Cannot withdraw more than the goal currently holds.');
        }

        $this->walletService->credit(
            wallet: $goal->wallet,
            currencyCode: $goal->currency_code,
            amountMinor: $amountMinor,
            idempotencyKey: $idempotencyKey,
            referenceType: 'savings_withdrawal',
            referenceId: $goal->id,
            type: 'savings_withdrawal',
        );

        $goal->current_amount_minor -= $amountMinor;
        if ($goal->current_amount_minor === 0 && $goal->status === 'active') {
            $goal->status = 'closed';
        }
        $goal->save();

        return $goal;
    }

    /**
     * Adds one day's simple interest to every active goal with a
     * nonzero balance. Intended to run once daily (see
     * AccrueSavingsInterest console command) — calling it more than
     * once for the same day double-accrues, since there's no ledger
     * entry (see class docblock) to check idempotency against.
     */
    public function accrueDailyInterestForAllActiveGoals(): int
    {
        $count = 0;

        SavingsGoal::where('status', 'active')
            ->where('current_amount_minor', '>', 0)
            ->where('interest_rate_bps', '>', 0)
            ->chunkById(200, function ($goals) use (&$count) {
                foreach ($goals as $goal) {
                    $dailyInterest = (int) round(
                        $goal->current_amount_minor * ($goal->interest_rate_bps / 10000) / 365
                    );

                    if ($dailyInterest > 0) {
                        $goal->increment('current_amount_minor', $dailyInterest);
                        $count++;
                    }
                }
            });

        return $count;
    }
}
