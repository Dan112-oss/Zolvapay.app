<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FraudAlert;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Blueprint Section 6, Phase 8 deliverable: "Compliance report
 * generation for regulator (per first country's requirements)."
 *
 * This is a generic transaction export, not a specific regulator's
 * prescribed format — no format was specified in the blueprint, and
 * every jurisdiction's actual reporting template differs (and usually
 * needs sign-off from whoever's handling that country's compliance
 * filing). What this gives you is the right underlying data, exportable
 * on demand, that a real regulatory template would be built from.
 */
class ComplianceController extends Controller
{
    /**
     * GET /api/admin/compliance/transactions.csv?from=...&to=...&type=...
     *
     * Streamed rather than loaded into memory — a compliance export
     * covering months of activity on a real user base could be a lot of
     * rows, and Transaction::chunk() below keeps memory flat regardless
     * of how many.
     */
    public function exportTransactions(Request $request): JsonResponse|StreamedResponse
    {
        $validator = Validator::make($request->all(), [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'type' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $query = Transaction::with(['initiatorWallet.user:id,name,email,country_code'])
            ->whereBetween('created_at', [$validated['from'], $validated['to']])
            ->orderBy('created_at');

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $filename = 'zolvapay-transactions-'.$validated['from'].'-to-'.$validated['to'].'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'transaction_id', 'type', 'status', 'amount_minor', 'currency_code',
                'fee_minor', 'initiator_user_id', 'initiator_email', 'initiator_country',
                'counterparty_wallet_id', 'created_at', 'completed_at',
            ]);

            $query->chunk(500, function ($transactions) use ($handle) {
                foreach ($transactions as $transaction) {
                    $user = $transaction->initiatorWallet->user ?? null;

                    fputcsv($handle, [
                        $transaction->id,
                        $transaction->type,
                        $transaction->status,
                        $transaction->amount,
                        $transaction->currency_code,
                        $transaction->fee,
                        $user->id ?? '',
                        $user->email ?? '',
                        $user->country_code ?? '',
                        $transaction->counterparty_wallet_id,
                        $transaction->created_at?->toIso8601String(),
                        $transaction->completed_at?->toIso8601String(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * GET /api/admin/compliance/fraud-alerts
     *
     * Read-only visibility into what FraudService has flagged —
     * complements the CSV export above rather than replacing it.
     */
    public function fraudAlerts(Request $request): JsonResponse
    {
        $alerts = FraudAlert::with('user:id,name,email')
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($alerts);
    }
}
