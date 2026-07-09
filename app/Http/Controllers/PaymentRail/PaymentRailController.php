<?php

namespace App\Http\Controllers\PaymentRail;

use App\Exceptions\FraudCheckFailedException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\PaymentRailRejectedException;
use App\Http\Controllers\Controller;
use App\Services\Fraud\FraudService;
use App\Services\Audit\AuditLogService;
use App\Services\PaymentRails\PaymentRailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PaymentRailController extends Controller
{
    public function __construct(
        private readonly PaymentRailService $paymentRailService,
        private readonly FraudService $fraudService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * POST /api/wallet/fund
     * Body: currency_code, amount, idempotency_key?
     *
     * Payer name/email/phone come from the authenticated user's own
     * profile rather than the request body — no reason to trust a client
     * to say who it's paying as.
     */
    public function fund(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->kyc_tier || $user->kyc_tier < 1) {
            return response()->json([
                'message' => 'Complete Tier 1 verification before funding your wallet.',
            ], 403);
        }

        $validated = $this->validateAmountRequest($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }
        [$currencyCode, $amountMinor] = $validated;

        $idempotencyKey = $request->input('idempotency_key') ?? (string) Str::uuid();

        try {
            $railTxn = $this->paymentRailService->fundWallet(
                user: $user,
                currencyCode: $currencyCode,
                amountMinor: $amountMinor,
                payerDetails: [
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ],
                idempotencyKey: $idempotencyKey,
            );
        } catch (PaymentRailRejectedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->auditLogService->record($user->id, 'funding.initiated', 'PaymentRailTransaction', $railTxn->id, $request);

        return response()->json([
            'message' => $railTxn->status === 'successful'
                ? 'Wallet funded.'
                : 'Funding initiated — complete payment to finish.',
            'payment_rail_transaction' => $railTxn,
            'checkout_url' => $railTxn->metadata['checkout_url'] ?? null,
        ], 201);
    }

    /**
     * POST /api/wallet/withdraw
     * Body: currency_code, amount, bank_code, account_number,
     *       account_name, idempotency_key?
     *
     * Field names (bank_code/account_number/account_name) are a
     * reasonable common denominator for now; a rail whose payout method
     * needs different fields (e.g. mobile money) would need its own
     * validation branch here later.
     */
    public function withdraw(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->kyc_tier || $user->kyc_tier < 1) {
            return response()->json([
                'message' => 'Complete Tier 1 verification before withdrawing.',
            ], 403);
        }

        $validated = $this->validateAmountRequest($request, [
            'bank_code' => ['required', 'string', 'max:50'],
            'account_number' => ['required', 'string', 'max:50'],
            'account_name' => ['required', 'string', 'max:255'],
        ]);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }
        [$currencyCode, $amountMinor, $extra] = $validated;

        $idempotencyKey = $request->input('idempotency_key') ?? (string) Str::uuid();

        try {
            $this->fraudService->checkVelocity($user, 'withdrawal', 'withdrawal');
        } catch (FraudCheckFailedException $e) {
            return response()->json(['message' => $e->getMessage()], 429);
        }

        try {
            $railTxn = $this->paymentRailService->withdrawFunds(
                user: $user,
                currencyCode: $currencyCode,
                amountMinor: $amountMinor,
                bankDetails: [
                    'bank_code' => $extra['bank_code'],
                    'account_number' => $extra['account_number'],
                    'account_name' => $extra['account_name'],
                ],
                idempotencyKey: $idempotencyKey,
            );
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (PaymentRailRejectedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->fraudService->checkLargeTransaction($user, $amountMinor, $currencyCode, 'withdrawal');
        $this->auditLogService->record($user->id, 'withdrawal.initiated', 'PaymentRailTransaction', $railTxn->id, $request);

        return response()->json([
            'message' => $railTxn->status === 'successful'
                ? 'Withdrawal completed.'
                : 'Withdrawal is being processed.',
            'payment_rail_transaction' => $railTxn,
        ], 201);
    }

    /**
     * GET /api/wallet/rail-transactions
     *
     * History of the caller's own funding/withdrawal attempts, most
     * recent first — same pagination pattern as TransferController::index().
     */
    public function index(Request $request): JsonResponse
    {
        $transactions = $request->user()
            ->wallet
            ->paymentRailTransactions()
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($transactions);
    }

    /**
     * Shared validation for fund() and withdraw(). $extraRules lets
     * withdraw() add its bank-detail fields without duplicating the
     * currency_code/amount checks.
     *
     * @return array{0: string, 1: int, 2: array}|JsonResponse
     */
    private function validateAmountRequest(Request $request, array $extraRules = []): array|JsonResponse
    {
        $supported = config('fx.supported_currencies', []);

        $validator = Validator::make($request->all(), array_merge([
            'currency_code' => ['required', 'string', 'size:3', 'in:'.implode(',', $supported)],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ], $extraRules));

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $currencyCode = strtoupper($validated['currency_code']);
        $amountMinor = (int) round(((float) $validated['amount']) * 100);

        unset($validated['currency_code'], $validated['amount']);

        return [$currencyCode, $amountMinor, $validated];
    }
}
