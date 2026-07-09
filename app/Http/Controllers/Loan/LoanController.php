<?php

namespace App\Http\Controllers\Loan;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\Audit\AuditLogService;
use App\Services\Loans\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

class LoanController extends Controller
{
    public function __construct(
        private readonly LoanService $loanService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * POST /api/loans/apply
     * Body: amount, currency_code
     *
     * Scored and, if approved, disbursed in this single call — see
     * LoanService's docblock for why there's no separate review step in
     * this phase.
     */
    public function apply(Request $request): JsonResponse
    {
        $user = $request->user();
        $supported = config('fx.supported_currencies', []);

        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency_code' => ['required', 'string', 'size:3', 'in:'.implode(',', $supported)],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $amountMinor = (int) round($validated['amount'] * 100);

        $loan = $this->loanService->applyForLoan($user, $amountMinor, strtoupper($validated['currency_code']));

        $this->auditLogService->record($user->id, 'loan.'.$loan->status, 'Loan', $loan->id, $request);

        return response()->json([
            'message' => $loan->status === 'active'
                ? 'Loan approved and disbursed.'
                : 'Loan application was not approved.',
            'loan' => $loan,
        ], $loan->status === 'active' ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['loans' => $request->user()->loans()->orderByDesc('created_at')->get()]);
    }

    /**
     * POST /api/loans/{loan}/repay
     * Body: amount, idempotency_key?
     */
    public function repay(Request $request, Loan $loan): JsonResponse
    {
        if ($loan->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Loan not found.'], 404);
        }

        $validator = Validator::make($request->all(), ['amount' => ['required', 'numeric', 'min:0.01']]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $amountMinor = (int) round($validator->validated()['amount'] * 100);
        $idempotencyKey = $request->input('idempotency_key') ?? (string) Str::uuid();

        try {
            $loan = $this->loanService->repay($loan, $amountMinor, $idempotencyKey);
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->auditLogService->record($request->user()->id, 'loan.repayment', 'Loan', $loan->id, $request);

        return response()->json([
            'message' => $loan->status === 'repaid' ? 'Loan fully repaid.' : 'Repayment applied.',
            'loan' => $loan,
        ]);
    }
}
