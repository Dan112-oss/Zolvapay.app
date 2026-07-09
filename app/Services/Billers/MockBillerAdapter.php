<?php

namespace App\Services\Billers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Fixed sample billers and instant settlement — mirrors the role of
 * MockPaymentRailAdapter/MockCardProcessorAdapter for their own modules.
 */
class MockBillerAdapter implements BillerAdapterInterface
{
    private const SAMPLE_BILLERS = [
        ['code' => 'MOCK-AIRTIME-MTN', 'name' => 'MTN Airtime', 'category' => 'airtime'],
        ['code' => 'MOCK-AIRTIME-AIRTEL', 'name' => 'Airtel Airtime', 'category' => 'airtime'],
        ['code' => 'MOCK-ELEC-IKEDC', 'name' => 'Ikeja Electric (Prepaid)', 'category' => 'electricity'],
        ['code' => 'MOCK-TV-DSTV', 'name' => 'DSTV Subscription', 'category' => 'tv'],
    ];

    public function listBillers(?string $category = null): array
    {
        return collect(self::SAMPLE_BILLERS)
            ->when($category, fn ($billers) => $billers->where('category', $category))
            ->map(fn ($b) => new Biller($b['code'], $b['name'], $b['category']))
            ->values()
            ->all();
    }

    public function payBill(string $reference, string $billerCode, string $customerId, int $amountMinor, string $currencyCode): BillPaymentResult
    {
        return new BillPaymentResult(
            status: 'successful',
            providerReference: 'mock_bill_'.Str::uuid(),
            message: 'Mock bill payment settled instantly.',
            raw: ['reference' => $reference, 'biller_code' => $billerCode, 'customer_id' => $customerId],
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        // Nothing here ever resolves as 'pending', so no real webhook
        // should ever arrive for the mock provider — see
        // MockPaymentRailAdapter's identical reasoning.
        return false;
    }

    public function parseWebhookPayload(array $payload): BillWebhookEvent
    {
        return new BillWebhookEvent(
            reference: $payload['reference'] ?? null,
            providerReference: $payload['provider_reference'] ?? null,
            status: $payload['status'] ?? 'pending',
            eventType: 'mock.event',
            raw: $payload,
        );
    }
}
