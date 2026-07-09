<?php

namespace App\Http\Controllers\Savings;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Models\SavingsGoal;
use App\Services\Audit\AuditLogService;
use App\Services\Savings\SavingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SavingsController extends Controller
{
    public function __construct(
        private readonly SavingsService $savingsService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * POST /api/savings/goals
     * Body: name, currency_code, target_amount?, target_date?, interest_rate_bps?
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->kyc_tier || $user->kyc_tier < 1) {
            return response()->json(['message' => 'Complete Tier 1 verification before opening a savings goal.'], 403);
        }

        $supported = config('fx.supported_currencies', []);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'currency_code' => ['required', 'string', 'size:3', 'in:'.implode(',', $supported)],
            'target_amount' => ['nullable', 'numeric', 'min:0.01'],
            'target_date' => ['nullable', 'date', 'after:today'],
            'interest_rate_bps' => ['nullable', 'integer', 'min:0', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        $goal = $this->savingsService->createGoal(
            user: $user,
            name: $validated['name'],
            currencyCode: strtoupper($validated['currency_code']),
            targetAmountMinor: isset($validated['target_amount']) ? (int) round($validated['target_amount'] * 100) : null,
            targetDate: $validated['target_date'] ?? null,
            interestRateBps: $validated['interest_rate_bps'] ?? 500, // 5% default
        );

        return response()->json(['message' => 'Savings goal created.', 'goal' => $goal], 201);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['goals' => $request->user()->savingsGoals()->orderByDesc('created_at')->get()]);
    }

    /**
     * POST /api/savings/goals/{goal}/deposit
     * Body: amount, idempotency_key?
     */
    public function deposit(Request $request, SavingsGoal $goal): JsonResponse
    {
        $denied = $this->denyIfNotOwner($request, $goal);
        if ($denied) {
            return $denied;
        }

        $validator = Validator::make($request->all(), ['amount' => ['required', 'numeric', 'min:0.01']]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $amountMinor = (int) round($validator->validated()['amount'] * 100);
        $idempotencyKey = $request->input('idempotency_key') ?? (string) Str::uuid();

        try {
            $goal = $this->savingsService->deposit($goal, $amountMinor, $idempotencyKey);
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->auditLogService->record($request->user()->id, 'savings.deposit', 'SavingsGoal', $goal->id, $request);

        return response()->json(['message' => 'Deposit added.', 'goal' => $goal]);
    }

    /**
     * POST /api/savings/goals/{goal}/withdraw
     * Body: amount, idempotency_key?
     */
    public function withdraw(Request $request, SavingsGoal $goal): JsonResponse
    {
        $denied = $this->denyIfNotOwner($request, $goal);
        if ($denied) {
            return $denied;
        }

        $validator = Validator::make($request->all(), ['amount' => ['required', 'numeric', 'min:0.01']]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $amountMinor = (int) round($validator->validated()['amount'] * 100);
        $idempotencyKey = $request->input('idempotency_key') ?? (string) Str::uuid();

        try {
            $goal = $this->savingsService->withdraw($goal, $amountMinor, $idempotencyKey);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->auditLogService->record($request->user()->id, 'savings.withdrawal', 'SavingsGoal', $goal->id, $request);

        return response()->json(['message' => 'Withdrawal completed.', 'goal' => $goal]);
    }

    private function denyIfNotOwner(Request $request, SavingsGoal $goal): ?JsonResponse
    {
        if ($goal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Savings goal not found.'], 404);
        }

        return null;
    }
}
