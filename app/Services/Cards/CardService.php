<?php

namespace App\Services\Cards;

use App\Models\Card;
use App\Models\User;

/**
 * Blueprint Section 2.6 / Phase 6 (Card Issuing).
 *
 * Scope note: this covers issuing and managing a card — freeze/
 * unfreeze, spend limit, on-demand detail reveal. It does NOT implement
 * real-time transaction authorization (a webhook from the processor at
 * swipe/checkout time that would actually debit the linked currency
 * sub-balance via WalletService). That's a materially bigger feature —
 * a live auth/decline decision has to happen synchronously inside the
 * processor's response window — and belongs in its own follow-up phase
 * once card issuance itself is solid.
 */
class CardService
{
    public function __construct(
        private readonly ?CardProcessorAdapterInterface $adapter = null,
    ) {
    }

    public function issueCard(User $user, string $currencyCode, string $cardholderName): Card
    {
        $wallet = $user->wallet;
        $adapter = $this->adapter ?? CardProcessorFactory::make();

        $result = $adapter->issueCard($user->id, $cardholderName, $currencyCode);

        return Card::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'currency_code' => $currencyCode,
            'processor' => config('card_processor.provider', 'mock'),
            'processor_card_id' => $result->processorCardId,
            'masked_pan' => $result->maskedPan,
            'last_four' => $result->lastFour,
            'expiry_month' => $result->expiryMonth,
            'expiry_year' => $result->expiryYear,
            'cardholder_name' => $cardholderName,
            'card_type' => 'virtual',
            'status' => 'active',
            'metadata' => ['issuance_response' => $result->raw],
        ]);
    }

    public function freeze(Card $card): Card
    {
        $adapter = $this->adapter ?? CardProcessorFactory::make();
        $adapter->freezeCard($card->processor_card_id);

        $card->status = 'frozen';
        $card->save();

        return $card;
    }

    public function unfreeze(Card $card): Card
    {
        $adapter = $this->adapter ?? CardProcessorFactory::make();
        $adapter->unfreezeCard($card->processor_card_id);

        $card->status = 'active';
        $card->save();

        return $card;
    }

    /**
     * $limitMinor of null removes any existing limit.
     */
    public function setSpendLimit(Card $card, ?int $limitMinor): Card
    {
        $adapter = $this->adapter ?? CardProcessorFactory::make();
        $adapter->setSpendLimit($card->processor_card_id, $limitMinor, $card->currency_code);

        $card->spend_limit_minor = $limitMinor;
        $card->save();

        return $card;
    }

    /**
     * Full PAN/CVV, fetched fresh from the processor every call — never
     * cached, never written to $card. Caller (CardController) is
     * responsible for making sure this only ever goes straight back to
     * the authenticated cardholder over HTTPS, never logged.
     */
    public function reveal(Card $card): array
    {
        $adapter = $this->adapter ?? CardProcessorFactory::make();

        return $adapter->revealCardDetails($card->processor_card_id);
    }
}
