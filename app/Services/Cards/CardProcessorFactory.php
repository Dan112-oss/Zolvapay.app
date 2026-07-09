<?php

namespace App\Services\Cards;

use InvalidArgumentException;

class CardProcessorFactory
{
    public static function make(): CardProcessorAdapterInterface
    {
        $provider = config('card_processor.provider', 'mock');

        return match ($provider) {
            'mock' => new MockCardProcessorAdapter(),
            'marqeta' => new MarqetaAdapter(
                config('card_processor.marqeta.application_token'),
                config('card_processor.marqeta.admin_access_token'),
                config('card_processor.marqeta.card_product_token'),
                config('card_processor.marqeta.base_url'),
            ),
            default => throw new InvalidArgumentException(
                "Unknown card processor [{$provider}]. Add a matching class and case here."
            ),
        };
    }
}
