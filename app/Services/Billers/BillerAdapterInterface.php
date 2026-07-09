<?php

namespace App\Services\Billers;

use Illuminate\Http\Request;

/**
 * Blueprint Section 2.7 (Bill Payments Service): "aggregator APIs
 * per region" behind one interface, same adapter-behind-a-factory
 * pattern as every other external integration in this codebase.
 */
interface BillerAdapterInterface
{
    /**
     * @return Biller[]
     */
    public function listBillers(?string $category = null): array;

    public function payBill(
        string $reference,
        string $billerCode,
        string $customerId,
        int $amountMinor,
        string $currencyCode,
    ): BillPaymentResult;

    public function verifyWebhookSignature(Request $request): bool;

    public function parseWebhookPayload(array $payload): BillWebhookEvent;
}
