<?php

namespace App\Http\Controllers\Fx;

use App\Exceptions\FraudCheckFailedException;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Services\Fraud\FraudService;
use App\Services\Audit\AuditLogService;
use App\Services\Fx\FxRateService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FxController extends Controller
{
    public function __construct(
        private readonly FxRateService $fxRateService,
        private readonly WalletService $walletService,
        private readonly FraudService $fraudService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * GET /api/fx/currencies
     *
     * The full list of currencies a wallet can hold/convert into —
     * config-driven (config/fx.php) so the frontend never hardcodes it.
     */
    public function currencies(): JsonResponse
    {
        return response()->json([
            'currencies' => config('fx.supported_currencies', []),
        ]);
    }

    /**
     * POST /api/fx/quote
     *
     * Read-only preview: "if I convert X of currency A, how much of
     * currency B would I get, at what rate?" Never touches a balance —
     * see convert() for the endpoint that actually moves money.
     *
     * Body: from_currency, to_currency, amount (major units, in from_currency)
     */
    public function quote(Request $request): JsonResponse
    {
        $validated = $this->validateQuoteRequest($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        [$fromCurrency, $toCurrency, $amountMinor] = $validated;

        $quote = $this->fxRateService->quote($fromCurrency, $toCurrency, $amountMinor);

        return response()->json($quote->toArray());
    }

    /**
     * POST /api/fx/convert
     *
     * Executes a conversion between two currency sub-balances of the
     * caller's own wallet (blueprint Section 2.4). Fetches a fresh quote
     * first (so the rate used is never older than config('fx.cache_minutes')),
     * then hands both amounts + the backing fx_rates id to
     * WalletService::convert() — which is the only thing that ever
     * writes to a balance.
     *
     * Body: from_currency, to_currency, amount (major units, in
     * from_currency), idempotency_key?
     */
    public function convert(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $this->validateQuoteRequest($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        [$fromCurrency, $toCurrency, $amountMinor] = $validated;

        // Conversions still move money between a user's own balances, so
        // hold them to the same Tier 1 bar as P2P transfers
        // (TransferController::store) rather than treating "it's my own
        // wallet" as automatically lower-risk.
        if (! $user->kyc_tier || $user->kyc_tier < 1) {
            return response()->json([
                'message' => 'Complete Tier 1 verification before converting currency.',
            ], 403);
        }

        $wallet = $user->wallet;
        if (! $wallet) {
            return response()->json(['message' => 'Wallet not found.'], 409);
        }

        $quote = $this->fxRateService->quote($fromCurrency, $toCurrency, $amountMinor);
        $idempotencyKey = $request->input('idempotency_key') ?? (string) Str::uuid();

        try {
            $this->fraudService->checkVelocity($user, 'conversion', 'fx_conversion');
        } catch (FraudCheckFailedException $e) {
            return response()->json(['message' => $e->getMessage()], 429);
        }

        try {
            $transaction = $this->walletService->convert(
                wallet: $wallet,
                fromCurrency: $quote->fromCurrency,
                toCurrency: $quote->toCurrency,
                amountMinorFrom: $quote->amountMinorFrom,
                amountMinorTo: $quote->amountMinorTo,
                fxRateId: $quote->fxRateId,
                idempotencyKey: $idempotencyKey,
            );
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $this->fraudService->checkLargeTransaction($user, $amountMinor, $quote->fromCurrency, 'fx_conversion');
        $this->auditLogService->record($user->id, 'conversion.created', 'Transaction', $transaction->id, $request);

        return response()->json([
            'message' => 'Conversion completed.',
            'transaction' => $transaction,
            'quote' => $quote->toArray(),
        ], 201);
    }

    /**
     * Shared validation for quote() and convert(): both take the same
     * three fields and both need the amount converted to minor units in
     * from_currency before touching FxRateService.
     *
     * @return array{0: string, 1: string, 2: int}|JsonResponse
     */
    private function validateQuoteRequest(Request $request): array|JsonResponse
    {
        $supported = config('fx.supported_currencies', []);

        $validator = Validator::make($request->all(), [
            'from_currency' => ['required', 'string', 'size:3', 'in:'.implode(',', $supported)],
            'to_currency' => ['required', 'string', 'size:3', 'in:'.implode(',', $supported)],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $fromCurrency = strtoupper($validated['from_currency']);
        $toCurrency = strtoupper($validated['to_currency']);

        if ($fromCurrency === $toCurrency) {
            return response()->json([
                'message' => 'From and to currency must be different.',
            ], 422);
        }

        $amountMinor = (int) round(((float) $validated['amount']) * 100);

        return [$fromCurrency, $toCurrency, $amountMinor];
    }
}
