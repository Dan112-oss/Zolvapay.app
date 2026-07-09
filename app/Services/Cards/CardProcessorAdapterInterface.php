<?php

namespace App\Services\Cards;

/**
 * Blueprint Section 2.6 (Card Issuing Service), same adapter-behind-a-
 * factory pattern as KYC/FX/payment rails. An implementation is
 * responsible for translating to/from whatever its own API expects and
 * MUST NEVER return full PAN/CVV from issueCard() — only revealDetails()
 * may, and CardService never persists what that returns.
 */
interface CardProcessorAdapterInterface
{
    /**
     * $externalUserId is ZolvaPay's own user id — used as an idempotency
     * key by adapters (like Marqeta) that require their own cardholder
     * record to exist before a card can be issued against it. The mock
     * adapter ignores it.
     */
    public function issueCard(string $externalUserId, string $cardholderName, string $currencyCode): CardIssuanceResult;

    public function freezeCard(string $processorCardId): bool;

    public function unfreezeCard(string $processorCardId): bool;

    /**
     * $limitMinor of null means "no limit" (remove any existing cap).
     */
    public function setSpendLimit(string $processorCardId, ?int $limitMinor, string $currencyCode): bool;

    /**
     * On-demand full card details (PAN/CVV/expiry) for display to the
     * cardholder only. Never cached, never written to our DB — see the
     * Card model's docblock.
     *
     * @return array{pan: string, cvv: string, expiry_month: int, expiry_year: int}
     */
    public function revealCardDetails(string $processorCardId): array;
}
