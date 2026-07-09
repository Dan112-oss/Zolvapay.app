<?php

namespace App\Http\Controllers\Wallet;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * GET /api/wallet
     *
     * Read-only. Returns the authenticated user's wallet balances so the
     * dashboard can show a real number instead of the $0.00 placeholder.
     * Moving money (P2P transfer) is Phase 3 — this endpoint never writes.
     */
    public function show(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet;

        if (! $wallet) {
            // Shouldn't happen for any user registered after this phase
            // shipped (AuthController creates one at signup), but existing
            // rows from before this migration won't have one.
            return response()->json([
                'wallet_id' => null,
                'balances' => [],
            ]);
        }

        $balances = $wallet->balances()->get()->map(function ($balance) {
            return [
                'currency_code' => $balance->currency_code,
                'available_balance_minor' => $balance->available_balance,
                // Assumes 2 decimal places for every currency (true for
                // USD/NGN/EUR/GBP/KES). Doesn't yet handle 0-decimal
                // currencies like JPY — revisit alongside Phase 4's
                // per-currency FX metadata.
                'available_balance' => round($balance->available_balance / 100, 2),
            ];
        });

        return response()->json([
            'wallet_id' => $wallet->id,
            'balances' => $balances,
        ]);
    }
}
