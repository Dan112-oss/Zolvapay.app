<?php

namespace App\Services\Fraud;

use App\Exceptions\FraudCheckFailedException;
use App\Models\FraudAlert;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;

/**
 * Blueprint Section 2.9 (Fraud/Risk Engine) / Phase 8.
 *
 * Scope note: the blueprint describes this as a separate service
 * consuming an event bus (Kafka) that every other service publishes to
 * asynchronously. This app has no event bus — it's a Laravel modular
 * monolith (see the blueprint's own "Note on architecture style" in
 * Section 4) — so this runs synchronously, inline, inside the same
 * request as the money movement it's checking. That's a real
 * simplification with a real cost: it adds a few DB queries to every
 * transfer/withdrawal/conversion/bill-payment request. It's the right
 * tradeoff for this app's current scale; if volume ever justifies a real
 * async fraud pipeline, this class's two public methods are the whole
 * surface that would need to move behind a queue/event consumer instead.
 *
 * Velocity counts against `ledger_entries` (filtered to entry_type =
 * 'debit') rather than a separate counter/cache, so there's nothing to
 * keep in sync with the ledger — it just reads what's already the
 * source of truth (Section 3's own rule).
 */
class FraudService
{
    /**
     * Blocks by throwing if $user has exceeded the configured limit for
     * $action within its window. Call this BEFORE moving any money, not
     * after — a check that only runs post-facto can't actually prevent
     * anything.
     */
    public function checkVelocity(User $user, string $action, string $referenceType): void
    {
        $limits = config("fraud.velocity_limits.{$action}");
        if (! $limits) {
            return; // no configured limit for this action — nothing to check
        }

        $wallet = $user->wallet;
        if (! $wallet) {
            return;
        }

        $since = now()->subSeconds($limits['window_seconds']);

        $count = LedgerEntry::where('wallet_id', $wallet->id)
            ->where('entry_type', 'debit')
            ->where('reference_type', $referenceType)
            ->where('created_at', '>=', $since)
            ->count();

        if ($count >= $limits['max']) {
            FraudAlert::create([
                'user_id' => $user->id,
                'alert_type' => 'velocity',
                'severity' => 'blocked',
                'description' => "User exceeded {$action} velocity limit ({$limits['max']} per {$limits['window_seconds']}s).",
                'context' => ['action' => $action, 'reference_type' => $referenceType, 'count' => $count],
            ]);

            throw FraudCheckFailedException::velocityExceeded($action, $limits['max'], $limits['window_seconds']);
        }
    }

    /**
     * Non-blocking — writes a fraud_alerts row for admin visibility if
     * $amountMinor exceeds the configured threshold for $currencyCode.
     * Call this AFTER the transaction completes; it never stops money
     * from moving, it only flags it for review.
     */
    public function checkLargeTransaction(User $user, int $amountMinor, string $currencyCode, string $context): void
    {
        $thresholds = config('fraud.large_transaction_thresholds', []);
        $thresholdMajor = $thresholds[$currencyCode] ?? $thresholds['default'] ?? null;

        if ($thresholdMajor === null) {
            return;
        }

        $amountMajor = $amountMinor / 100;

        if ($amountMajor < $thresholdMajor) {
            return;
        }

        FraudAlert::create([
            'user_id' => $user->id,
            'alert_type' => 'large_transaction',
            'severity' => 'warning',
            'description' => "Large {$context}: {$amountMajor} {$currencyCode} (threshold {$thresholdMajor}).",
            'context' => ['context' => $context, 'amount_minor' => $amountMinor, 'currency_code' => $currencyCode],
        ]);
    }
}
