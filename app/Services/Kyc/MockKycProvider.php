<?php

namespace App\Services\Kyc;

use App\Models\KycRecord;

/**
 * Placeholder adapter used until a real vendor is wired up. It makes no
 * external API call — it simply leaves the submission as 'pending' so a
 * human reviewer picks it up in the admin KYC queue.
 *
 * To go live with a real vendor:
 *   1. Add KYC_API_KEY / KYC_API_SECRET / KYC_PARTNER_ID to .env (the
 *      placeholders already exist there).
 *   2. Write a new class implementing KycProviderInterface, e.g.
 *      SmileIdProvider, that calls the vendor's API in verify().
 *   3. Add a case for it in KycProviderFactory::make().
 * KycController and the admin queue do not need to change.
 */
class MockKycProvider implements KycProviderInterface
{
    public function verify(KycRecord $record): KycResult
    {
        return new KycResult(
            status: 'pending',
            reason: null,
            raw: [
                'provider' => 'mock',
                'note' => 'Awaiting manual admin review — no KYC vendor is integrated yet.',
            ],
        );
    }
}
