<?php

namespace App\Services\Loans;

use App\Models\LedgerEntry;
use App\Models\User;

/**
 * Blueprint Section 2.8: "Micro-loan scoring engine (rules-based
 * initially, ML-based later using transaction history)."
 *
 * This is the "initially" version. Rules, deliberately conservative and
 * simple enough to explain to a regulator or a support agent in one
 * sentence each — swapping in an ML-based scorer later only needs to
 * preserve this class's public score() shape, not its internals:
 *
 *   1. KYC Tier 1+ required (no exceptions).
 *   2. Account must be at least 30 days old.
 *   3. Max eligible amount = 20% of the user's total wallet debit volume
 *      (in the requested currency) over the trailing 90 days — a proxy
 *      for "how much money genuinely flows through this account,"
 *      using the ledger itself as the transaction-history signal the
 *      blueprint's own "ML-based later" line points at.
 *   4. Flat interest rate by KYC tier: lower tier = higher rate (less
 *      verified = more risk).
 */
class LoanScoringService
{
    private const MIN_ACCOUNT_AGE_DAYS = 30;

    private const ELIGIBLE_VOLUME_FRACTION = 0.20;

    private const VOLUME_LOOKBACK_DAYS = 90;

    private const INTEREST_RATE_BPS_BY_TIER = [
        1 => 2500, // 25% flat — least verified tier eligible for loans
        2 => 1800,
        3 => 1200, // most verified — best rate
    ];

    public function score(User $user, int $requestedAmountMinor, string $currencyCode): LoanScoreResult
    {
        if (! $user->kyc_tier || $user->kyc_tier < 1) {
            return LoanScoreResult::rejected('KYC Tier 1 or higher is required.');
        }

        $accountAgeDays = $user->created_at->diffInDays(now());
        if ($accountAgeDays < self::MIN_ACCOUNT_AGE_DAYS) {
            return LoanScoreResult::rejected(
                'Account must be at least '.self::MIN_ACCOUNT_AGE_DAYS.' days old (currently '.$accountAgeDays.').'
            );
        }

        $wallet = $user->wallet;
        if (! $wallet) {
            return LoanScoreResult::rejected('No wallet found.');
        }

        $trailingVolume = (int) LedgerEntry::where('wallet_id', $wallet->id)
            ->where('currency_code', $currencyCode)
            ->where('entry_type', 'debit')
            ->where('created_at', '>=', now()->subDays(self::VOLUME_LOOKBACK_DAYS))
            ->sum('amount');

        $maxEligibleMinor = (int) round($trailingVolume * self::ELIGIBLE_VOLUME_FRACTION);

        if ($maxEligibleMinor <= 0) {
            return LoanScoreResult::rejected(
                'Not enough account activity yet to score a loan. Use your wallet more and try again later.'
            );
        }

        $interestRateBps = self::INTEREST_RATE_BPS_BY_TIER[$user->kyc_tier] ?? self::INTEREST_RATE_BPS_BY_TIER[1];
        $approvedAmountMinor = min($requestedAmountMinor, $maxEligibleMinor);

        return LoanScoreResult::approved($approvedAmountMinor, $interestRateBps, [
            'trailing_volume_minor' => $trailingVolume,
            'max_eligible_minor' => $maxEligibleMinor,
            'account_age_days' => $accountAgeDays,
            'kyc_tier' => $user->kyc_tier,
        ]);
    }
}
