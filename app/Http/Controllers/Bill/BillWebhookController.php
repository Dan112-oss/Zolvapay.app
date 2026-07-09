<?php

namespace App\Http\Controllers\Bill;

use App\Http\Controllers\Controller;
use App\Services\Billers\BillerFactory;
use App\Services\Billers\BillPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * POST /api/webhooks/billers/{provider}
 *
 * Same shape/reasoning as PaymentRailWebhookController (Phase 5) —
 * outside auth:sanctum, authenticated via the adapter's own signature
 * check instead.
 */
class BillWebhookController extends Controller
{
    public function __construct(
        private readonly BillPaymentService $billPaymentService,
    ) {
    }

    public function handle(Request $request, string $provider): JsonResponse
    {
        try {
            $adapter = BillerFactory::forProvider($provider);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => 'Unknown provider.'], 404);
        }

        if (! $adapter->verifyWebhookSignature($request)) {
            Log::warning('biller_webhook_signature_invalid', ['provider' => $provider]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        try {
            $event = $adapter->parseWebhookPayload($request->all());
            $this->billPaymentService->handleWebhookEvent($event);
        } catch (\Throwable $e) {
            Log::error('biller_webhook_processing_failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['message' => 'ok']);
    }
}
