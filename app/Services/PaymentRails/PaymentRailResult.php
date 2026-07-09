<?php

namespace App\Services\PaymentRails;

/**
 * Result of PaymentRailAdapterInterface::initiateFunding()/initiateWithdrawal().
 *
 * $status reflects the RAIL's state, not just whether the HTTP call
 * succeeded: 'successful' means the money has definitely moved already
 * (e.g. MockPaymentRailAdapter, which never needs a webhook); 'pending'
 * means the rail accepted the request but confirmation will arrive later
 * via webhook (e.g. a real Flutterwave charge/transfer); 'failed' means
 * the rail rejected it outright (e.g. an invalid bank account).
 */
final class PaymentRailResult
{
    public function __construct(
        public readonly string $status, // successful, pending, failed
        public readonly ?string $railReference = null,
        public readonly ?string $checkoutUrl = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'successful';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
