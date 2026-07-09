<?php

namespace App\Http\Controllers\Transfer;

use App\Exceptions\FraudCheckFailedException;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Jobs\SendTransferNotification;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fraud\FraudService;
use App\Services\Audit\AuditLogService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TransferController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly FraudService $fraudService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * POST /api/transfers
     *
     * In-app P2P transfer, ZolvaPay-to-ZolvaPay (blueprint Section 2.5).
     * Resolves the recipient by email, then hands both wallets to the
     * existing WalletService::transfer() — this endpoint never touches a
     * balance directly.
     *
     * Body: recipient_email, amount, currency_code, note?, idempotency_key?
     * 'amount' is in major units (e.g. 25.50), converted to minor units
     * here, matching what the frontend form naturally collects. Contrast
     * with the admin adjustment endpoints, which take amount_minor
     * directly since those are operated by staff, not end users.
     */
    public function store(Request $request): JsonResponse
    {
        $sender = $request->user();

        $validator = Validator::make($request->all(), [
            'recipient_email' => ['required', 'string', 'email'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency_code' => ['required', 'string', 'size:3'],
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
        $currencyCode = strtoupper($validated['currency_code']);

        // Transfers are locked behind Tier 1 KYC — the dashboard banner
        // already tells the user this; enforce it server-side too.
        if (! $sender->kyc_tier || $sender->kyc_tier < 1) {
            return response()->json([
                'message' => 'Complete Tier 1 verification before sending money.',
            ], 403);
        }

        if (strcasecmp($validated['recipient_email'], $sender->email) === 0) {
            return response()->json([
                'message' => 'You cannot send money to yourself.',
            ], 422);
        }

        $recipient = User::where('email', $validated['recipient_email'])->first();

        if (! $recipient) {
            return response()->json([
                'message' => 'No ZolvaPay account found for that email address.',
            ], 404);
        }

        if ($recipient->status !== 'active') {
            return response()->json([
                'message' => 'This recipient cannot receive funds right now.',
            ], 409);
        }

        $senderWallet = $sender->wallet;
        $recipientWallet = $recipient->wallet;

        if (! $senderWallet || ! $recipientWallet) {
            // Shouldn't happen — AuthController creates a wallet at
            // signup — but guard against pre-Phase-2 accounts.
            return response()->json([
                'message' => 'Wallet not found for sender or recipient.',
            ], 409);
        }

        $amountMinor = (int) round(((float) $validated['amount']) * 100);
        $idempotencyKey = $validated['idempotency_key'] ?? (string) Str::uuid();

        try {
            $this->fraudService->checkVelocity($sender, 'transfer', 'p2p_transfer');
        } catch (FraudCheckFailedException $e) {
            return response()->json(['message' => $e->getMessage()], 429);
        }

        try {
            $transaction = $this->walletService->transfer(
                from: $senderWallet,
                to: $recipientWallet,
                currencyCode: $currencyCode,
                amountMinor: $amountMinor,
                idempotencyKey: $idempotencyKey,
                referenceId: $recipient->id,
            );
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $this->fraudService->checkLargeTransaction($sender, $amountMinor, $currencyCode, 'p2p_transfer');
        $this->auditLogService->record($sender->id, 'transfer.created', 'Transaction', $transaction->id, $request);

        if (! empty($validated['note'])) {
            // Best-effort — the transfer already succeeded above, so a
            // failure here should never surface as a failed transfer.
            $transaction->update(['metadata' => ['note' => $validated['note']]]);
        }

        // Queued — never send notifications synchronously in the
        // payment path (blueprint Section 2.10).
        SendTransferNotification::dispatch($transaction->id);

        return response()->json([
            'message' => 'Transfer completed.',
            'transaction' => $transaction,
        ], 201);
    }

    /**
     * GET /api/transactions
     *
     * Paginated history for the authenticated user's wallet, across both
     * sides of a transaction (sender or recipient). Each row is annotated
     * with 'direction' and 'counterparty_name' relative to the viewer, so
     * the frontend doesn't need to know which wallet id is "theirs".
     */
    public function index(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet;

        if (! $wallet) {
            // Match the shape of a real (empty) paginator response below,
            // rather than a differently-shaped ad hoc object.
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'total' => 0,
            ]);
        }

        $transactions = Transaction::with(['initiatorWallet.user:id,name,email', 'counterpartyWallet.user:id,name,email'])
            ->where(function ($query) use ($wallet) {
                $query->where('initiator_wallet_id', $wallet->id)
                    ->orWhere('counterparty_wallet_id', $wallet->id);
            })
            ->orderByDesc('created_at')
            ->paginate(20);

        $transactions->getCollection()->transform(function (Transaction $transaction) use ($wallet) {
            $isInitiator = $transaction->initiator_wallet_id === $wallet->id;
            $counterpartyWallet = $isInitiator ? $transaction->counterpartyWallet : $transaction->initiatorWallet;

            return [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'status' => $transaction->status,
                'direction' => $this->directionFor($transaction, $isInitiator),
                'amount_minor' => $transaction->amount,
                'amount' => round($transaction->amount / 100, 2),
                'currency_code' => $transaction->currency_code,
                'counterparty_name' => $counterpartyWallet?->is_system
                    ? 'ZolvaPay'
                    : ($counterpartyWallet?->user?->name ?? 'Unknown'),
                'note' => $transaction->metadata['note'] ?? null,
                'created_at' => $transaction->created_at,
            ];
        });

        return response()->json($transactions);
    }

    /**
     * A p2p_transfer is 'sent' or 'received' depending on which side the
     * viewer's wallet is on. Every other transaction type (admin_adjustment
     * today; fx_conversion, bill_payment, etc. in later phases) is framed
     * as 'credit'/'debit' from the viewer's own wallet instead, since
     * those aren't between two ZolvaPay users.
     */
    private function directionFor(Transaction $transaction, bool $isInitiator): string
    {
        if ($transaction->type === 'p2p_transfer') {
            return $isInitiator ? 'sent' : 'received';
        }

        return $isInitiator ? 'debit' : 'credit';
    }
}
