<?php

namespace App\Services\Billers;

/**
 * One entry from BillerAdapterInterface::listBillers() — enough for the
 * frontend to render a picker and enough for BillPaymentService to pass
 * back to payBill() unchanged.
 */
final class Biller
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $category,
        public readonly ?string $currencyCode = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'currency_code' => $this->currencyCode,
        ];
    }
}
