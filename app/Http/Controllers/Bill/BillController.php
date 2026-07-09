<?php

namespace App\Http\Controllers\Bill;

use App\Exceptions\FraudCheckFailedException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\PaymentRailRejectedException;
use App\Http\Controllers\Controller;
use App\Services\Billers\BillPaymentService;
use App\Services\Fraud\FraudService;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BillController extends Controller
{
    public function __construct(
        private readonly BillPaymentService $billPaymentService,
        private readonly FraudService $fraudService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * GET /api/bills/categories
     */
    public function categories(): JsonResponse
    {
        return response()->json(['categories' => config('billers.categories', [])]);
    }

    /**
     * GET /api/bills/billers?category=airtime
     */
    public function billers(Request $request): JsonResponse
    {
        $billers = $this->billPaymentService->listBillers($request->query('category'));

        return response()->json([
            'billers' => array_map(fn ($b) => $b->toArray(), $billers),
        ]);
    }

    /**
     * POST /api/bills/pay
     * Body: biller_code, biller_name, category, customer_id, currency_code,
     *       amount, idempotency_key?
     *
     * biller_name/category are accepted from the client (sourced from
     * the billers() list above) rather than re-derived server-side —
     * simpler than requiring every adapter to also implement a
     * getBillerByCode() lookup. They're only ever used for display/
     * audit (bill_payments.biller_name/category), never for anything
     * money-affecting.
     */
    public function pay(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->kyc_tier || $user->kyc_tier < 1) {
            return response()->json([
                'message' => 'Complete Tier 1 verification before paying bills.',
            ], 403);
        }

        $supported = config('fx.supported_currencies', []);

        $validator = Validator::make($request->all(), [
            'biller_code' => ['required', 'string', 'max:100'],
            'biller_name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:'.implode(',', config('billers.categories', []))],
            'customer_id' => ['required', 'string', 'max:100'],
            'currency_code' => ['required', 'string', 'size:3', 'in:'.implode(',', $supported)],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $amountMinor = (int) round(((float) $validated['amount']) * 100);
        $idempotencyKey = $request->input('idempotency_key') ?? (string) Str::uuid();

        try {
            $this->fraudService->checkVelocity($user, 'bill_payment', 'bill_payment');
        } catch (FraudCheckFailedException $e) {
            return response()->json(['message' => $e->getMessage()], 429);
        }

        try {
            $billPayment = $this->billPaymentService->payBill(
                user: $user,
                billerCode: $validated['biller_code'],
                billerName: $validated['biller_name'],
                category: $validated['category'],
                customerId: $validated['customer_id'],
                amountMinor: $amountMinor,
                currencyCode: strtoupper($validated['currency_code']),
                idempotencyKey: $idempotencyKey,
            );
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (PaymentRailRejectedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->fraudService->checkLargeTransaction($user, $amountMinor, strtoupper($validated['currency_code']), 'bill_payment');
        $this->auditLogService->record($user->id, 'bill_payment.initiated', 'BillPayment', $billPayment->id, $request);

        return response()->json([
            'message' => $billPayment->status === 'successful' ? 'Bill paid.' : 'Bill payment is being processed.',
            'bill_payment' => $billPayment,
        ], 201);
    }

    /**
     * GET /api/bills/history
     */
    public function index(Request $request): JsonResponse
    {
        $payments = $request->user()
            ->wallet
            ->billPayments()
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($payments);
    }
}
