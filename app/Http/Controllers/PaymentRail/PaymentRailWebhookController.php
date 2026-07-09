<?php

namespace App\Http\Controllers\PaymentRail;

use App\Http\Controllers\Controller;
use App\Services\PaymentRails\PaymentRailFactory;
use App\Services\PaymentRails\PaymentRailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * POST /api/webhooks/payment-rails/{rail}
 *
 * Deliberately outside the auth:sanctum group in routes/api.php — the
 * rail isn't a logged-in ZolvaPay user, it authenticates itself via
 * FlutterwaveAdapter::verifyWebhookSignature() instead. Always returns
 * 200 as long as the signature/shape checks out, even if the referenced
 * payment_rail_transactions row can't be found — rails retry aggressively
 * on non-2xx, and a stale/unknown reference isn't something retrying
 * will fix.
 */
class PaymentRailWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentRailService $paymentRailService,
    ) {
    }

    public function handle(Request $request, string $rail): JsonResponse
    {
        try {
            $adapter = PaymentRailFactory::forRail($rail);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => 'Unknown rail.'], 404);
        }

        if (! $adapter->verifyWebhookSignature($request)) {
            Log::warning('payment_rail_webhook_signature_invalid', ['rail' => $rail]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->all();

        try {
            $event = $adapter->parseWebhookPayload($payload);
            $this->paymentRailService->handleWebhookEvent($rail, $event);
        } catch (\Throwable $e) {
            // Log and still return 200 — an unparseable/unexpected
            // payload is a bug to investigate, not something the rail
            // should keep retrying forever.
            Log::error('payment_rail_webhook_processing_failed', [
                'rail' => $rail,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['message' => 'ok']);
    }
}
