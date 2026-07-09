<?php

namespace App\Services\Kyc;

use App\Models\KycRecord;

/**
 * Adapter interface for KYC/AML vendors (blueprint Section 2.2 / 4:
 * Smile ID, Sumsub, Onfido, etc.) — mirrors the "Payment Rail Adapter"
 * pattern used elsewhere in the blueprint so a new vendor is a new class,
 * not a rewrite of KycController.
 */
interface KycProviderInterface
{
    /**
     * Submit a KYC record for verification and return the outcome.
     *
     * Synchronous/mock providers can return 'approved' or 'rejected'
     * immediately. Real vendors that verify asynchronously should return
     * 'pending' here; a future webhook endpoint would then update the
     * record when the vendor calls back.
     */
    public function verify(KycRecord $record): KycResult;
}
