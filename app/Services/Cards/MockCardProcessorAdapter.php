<?php

namespace App\Services\Cards;

use Illuminate\Support\Str;

/**
 * Generates clearly-fake card data with no network call — mirrors the
 * role of MockKycProvider/MockFxProvider/MockPaymentRailAdapter. Never
 * usable for a real charge; every PAN starts with 4000 (a well-known
 * "test" BIN pattern) so it's obvious at a glance this isn't real.
 */
class MockCardProcessorAdapter implements CardProcessorAdapterInterface
{
    public function issueCard(string $externalUserId, string $cardholderName, string $currencyCode): CardIssuanceResult
    {
        $lastFour = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $expiry = now()->addYears(3);

        return new CardIssuanceResult(
            processorCardId: 'mock_card_'.Str::uuid(),
            maskedPan: "•••• •••• •••• {$lastFour}",
            lastFour: $lastFour,
            expiryMonth: (int) $expiry->format('n'),
            expiryYear: (int) $expiry->format('Y'),
            raw: ['cardholder_name' => $cardholderName, 'currency_code' => $currencyCode],
        );
    }

    public function freezeCard(string $processorCardId): bool
    {
        return true;
    }

    public function unfreezeCard(string $processorCardId): bool
    {
        return true;
    }

    public function setSpendLimit(string $processorCardId, ?int $limitMinor, string $currencyCode): bool
    {
        return true;
    }

    public function revealCardDetails(string $processorCardId): array
    {
        return [
            'pan' => '4000 0000 0000 '.substr($processorCardId, -4),
            'cvv' => (string) random_int(100, 999),
            'expiry_month' => (int) now()->addYears(3)->format('n'),
            'expiry_year' => (int) now()->addYears(3)->format('Y'),
        ];
    }
}
