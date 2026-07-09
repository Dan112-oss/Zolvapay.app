<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Services\Audit\AuditLogService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WalletAdminController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * GET /api/admin/wallets
     *
     * Lists non-system wallets with their owner and current balances.
     */
    public function index(): JsonResponse
    {
        $wallets = Wallet::with(['user:id,name,email', 'balances'])
            ->where('is_system', false)
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json($wallets);
    }

    /**
     * GET /api/admin/wallets/{wallet}/ledger
     *
     * Read-only reconciliation view (blueprint Phase 2 deliverable). Also
     * recomputes each currency's balance directly from ledger_entries and
     * flags any mismatch against the wallet_balances cache — per the
     * blueprint's "if they ever disagree, the ledger wins" rule, this is
     * how an admin would notice that in practice.
     */
    public function ledger(Request $request, Wallet $wallet): JsonResponse
    {
        $entries = $wallet->ledgerEntries()
            ->orderByDesc('created_at')
            ->paginate(50);

        $recomputed = $wallet->ledgerEntries()
            ->selectRaw('currency_code, SUM(CASE WHEN entry_type = ? THEN amount ELSE -amount END) as computed_balance', ['credit'])
            ->groupBy('currency_code')
            ->get()
            ->keyBy('currency_code');

        $reconciliation = $wallet->balances->map(function ($balance) use ($recomputed) {
            $computed = (int) optional($recomputed->get($balance->currency_code))->computed_balance;

            return [
                'currency_code' => $balance->currency_code,
                'cached_balance' => $balance->available_balance,
                'computed_from_ledger' => $computed,
                'matches' => $computed === $balance->available_balance,
            ];
        });

        return response()->json([
            'wallet_id' => $wallet->id,
            'reconciliation' => $reconciliation,
            'entries' => $entries,
        ]);
    }

    /**
     * POST /api/admin/wallets/{wallet}/credit
     * Body: currency_code, amount_minor, note?, idempotency_key?
     */
    public function credit(Request $request, Wallet $wallet): JsonResponse
    {
        return $this->adjust($request, $wallet, 'credit');
    }

    /**
     * POST /api/admin/wallets/{wallet}/debit
     * Body: currency_code, amount_minor, note?, idempotency_key?
     */
    public function debit(Request $request, Wallet $wallet): JsonResponse
    {
        return $this->adjust($request, $wallet, 'debit');
    }

    private function adjust(Request $request, Wallet $wallet, string $direction): JsonResponse
    {
        if ($wallet->is_system) {
            return response()->json([
                'message' => 'The system wallet cannot be adjusted directly.',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'currency_code' => ['required', 'string', 'size:3'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $idempotencyKey = $validated['idempotency_key'] ?? (string) Str::uuid();

        try {
            $transaction = $direction === 'credit'
                ? $this->walletService->credit(
                    wallet: $wallet,
                    currencyCode: strtoupper($validated['currency_code']),
                    amountMinor: $validated['amount_minor'],
                    idempotencyKey: $idempotencyKey,
                    referenceId: $request->user()->id,
                )
                : $this->walletService->debit(
                    wallet: $wallet,
                    currencyCode: strtoupper($validated['currency_code']),
                    amountMinor: $validated['amount_minor'],
                    idempotencyKey: $idempotencyKey,
                    referenceId: $request->user()->id,
                );
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $this->auditLogService->record(
            $request->user()->id,
            'wallet.admin_'.$direction,
            'Transaction',
            $transaction->id,
            $request,
        );

        return response()->json([
            'message' => 'Wallet '.$direction.'ed.',
            'transaction' => $transaction,
        ], 201);
    }
}
