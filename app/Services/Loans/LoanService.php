<?php

namespace App\Services\Loans;

use App\Models\Loan;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Blueprint Section 2.8 / Phase 9 (Savings & Loans).
 *
 * DESIGN CHOICE worth flagging explicitly: applyForLoan() auto-approves
 * and auto-disburses in one call when LoanScoringService approves it —
 * there is no separate admin review step, unlike KYC (Phase 1) which
 * deliberately has one. That's appropriate for a small, rules-scored
 * micro-loan product; it stops being appropriate the moment loan sizes
 * or risk grow past what the scoring rules alone should be trusted to
 * gatekeep. If/when that line is crossed, insert a 'pending' review
 * step between score() and disburse() — the schema already has a
 * 'pending' status ready for it, it's just unused today.
 *
 * Interest is SIMPLE, computed once at disbursement (principal +
 * principal * rate) — see the loans migration's docblock.
 */
class LoanService
{
    private const TERM_DAYS = 90;

    public function __construct(
        private readonly WalletService $walletService,
        private readonly LoanScoringService $scoringService,
    ) {
    }

    public function applyForLoan(User $user, int $requestedAmountMinor, string $currencyCode): Loan
    {
        $score = $this->scoringService->score($user, $requestedAmountMinor, $currencyCode);

        if (! $score->approved) {
            return Loan::create([
                'user_id' => $user->id,
                'wallet_id' => $user->wallet->id,
                'currency_code' => $currencyCode,
                'principal_minor' => $requestedAmountMinor,
                'interest_rate_bps' => 0,
                'outstanding_balance_minor' => 0,
                'status' => 'rejected',
                'rejection_reason' => $score->rejectionReason,
            ]);
        }

        $interestMinor = (int) round($score->approvedAmountMinor * ($score->interestRateBps / 10000));
        $outstandingMinor = $score->approvedAmountMinor + $interestMinor;

        $loan = Loan::create([
            'user_id' => $user->id,
            'wallet_id' => $user->wallet->id,
            'currency_code' => $currencyCode,
            'principal_minor' => $score->approvedAmountMinor,
            'interest_rate_bps' => $score->interestRateBps,
            'outstanding_balance_minor' => $outstandingMinor,
            'status' => 'active',
            'disbursed_at' => now(),
            'due_date' => now()->addDays(self::TERM_DAYS),
            'metadata' => $score->context,
        ]);

        $this->walletService->credit(
            wallet: $user->wallet,
            currencyCode: $currencyCode,
            amountMinor: $score->approvedAmountMinor, // principal only — interest isn't disbursed, it's owed
            idempotencyKey: 'loan-disbursement-'.$loan->id,
            referenceType: 'loan_disbursement',
            referenceId: $loan->id,
            type: 'loan_disbursement',
        );

        return $loan->fresh();
    }

    /**
     * Repays up to $amountMinor against the loan's outstanding balance
     * (principal + interest combined — no separate principal/interest
     * allocation, consistent with the simple, non-amortizing model this
     * phase uses). Overpayment beyond the outstanding balance is
     * rejected rather than silently capped, so the caller's stated
     * amount always either fully applies or fails clearly.
     */
    public function repay(Loan $loan, int $amountMinor, string $idempotencyKey): Loan
    {
        if ($loan->status !== 'active') {
            throw new InvalidArgumentException('Only an active loan can be repaid.');
        }
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Repayment amount must be positive.');
        }
        if ($amountMinor > $loan->outstanding_balance_minor) {
            throw new InvalidArgumentException('Repayment amount exceeds the outstanding balance.');
        }

        return DB::transaction(function () use ($loan, $amountMinor, $idempotencyKey) {
            // Let InsufficientBalanceException propagate — the loan is
            // only updated below if the debit actually succeeds.
            $this->walletService->debit(
                wallet: $loan->wallet,
                currencyCode: $loan->currency_code,
                amountMinor: $amountMinor,
                idempotencyKey: $idempotencyKey,
                referenceType: 'loan_repayment',
                referenceId: $loan->id,
                type: 'loan_repayment',
            );

            $loan->outstanding_balance_minor -= $amountMinor;
            if ($loan->outstanding_balance_minor === 0) {
                $loan->status = 'repaid';
            }
            $loan->save();

            return $loan;
        });
    }
}
