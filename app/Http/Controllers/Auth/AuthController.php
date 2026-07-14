<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletBalance;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * POST /api/auth/register
     *
     * Creates a new user and returns an API token, so the frontend can
     * log the user straight in after signup (matching the existing
     * signup.html -> auto-login -> dashboard.html flow).
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'country_code' => ['required', 'string', 'size:2'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'country_code' => strtoupper($validated['country_code']),
                'password' => $validated['password'], // hashed automatically via the 'hashed' cast
                'kyc_tier' => 0,
                'status' => 'active',
            ]);

            // Every user gets exactly one wallet, created in the same
            // transaction as the user row so we never end up with a
            // walletless account.
            $wallet = Wallet::create(['user_id' => $user->id]);

            // Give the wallet a zero-balance row in the currency matching
            // the user's country, so the dashboard/Convert/Cards screens
            // have something to show instead of "no balance yet" — a
            // brand-new wallet is otherwise indistinguishable from one
            // with zero currency support at all. A zero-balance row needs
            // no ledger entry (nothing has happened yet), so this stays
            // consistent with wallet_balances being a cache of the ledger.
            WalletBalance::create([
                'wallet_id' => $wallet->id,
                'currency_code' => self::defaultCurrencyForCountry($validated['country_code']),
                'available_balance' => 0,
                'ledger_balance' => 0,
            ]);

            return $user;
        });

        $token = $user->createToken('zolvapay-web')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * Maps a signup country to the currency a new wallet should start
     * with. Keep in sync with config('fx.supported_currencies') — this
     * only ever returns a currency from that list, falling back to USD
     * for any country not explicitly mapped (the user can still add/hold
     * any supported currency later via Fund/Convert regardless of this
     * starting choice).
     */
    private static function defaultCurrencyForCountry(string $countryCode): string
    {
        static $map = [
            'NG' => 'NGN',
            'KE' => 'KES',
            'GB' => 'GBP',
            // EU member states that use the euro.
            'AT' => 'EUR', 'BE' => 'EUR', 'CY' => 'EUR', 'EE' => 'EUR',
            'FI' => 'EUR', 'FR' => 'EUR', 'DE' => 'EUR', 'GR' => 'EUR',
            'IE' => 'EUR', 'IT' => 'EUR', 'LV' => 'EUR', 'LT' => 'EUR',
            'LU' => 'EUR', 'MT' => 'EUR', 'NL' => 'EUR', 'PT' => 'EUR',
            'SK' => 'EUR', 'SI' => 'EUR', 'ES' => 'EUR', 'HR' => 'EUR',
        ];

        return $map[strtoupper($countryCode)] ?? 'USD';
    }

    /**
     * POST /api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            // Deliberately vague — never reveal whether the email exists.
            return response()->json([
                'message' => 'These credentials do not match our records.',
            ], 401);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'This account is not active. Please contact support.',
            ], 403);
        }

        $token = $user->createToken('zolvapay-web')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * POST /api/auth/logout
     *
     * Revokes only the token used to make this request, not all of the
     * user's sessions/devices.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }
}
