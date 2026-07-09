<?php

use App\Http\Controllers\Admin\ComplianceController;
use App\Http\Controllers\Admin\KycAdminController;
use App\Http\Controllers\Admin\WalletAdminController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Bill\BillController;
use App\Http\Controllers\Bill\BillWebhookController;
use App\Http\Controllers\Card\CardController;
use App\Http\Controllers\Fx\FxController;
use App\Http\Controllers\Kyc\KycController;
use App\Http\Controllers\Loan\LoanController;
use App\Http\Controllers\PaymentRail\PaymentRailController;
use App\Http\Controllers\PaymentRail\PaymentRailWebhookController;
use App\Http\Controllers\Savings\SavingsController;
use App\Http\Controllers\Transfer\TransferController;
use App\Http\Controllers\Wallet\WalletController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Phase 0: health-check endpoint to confirm the API is reachable.
|
| Phase 1: auth routes (register/login/logout/me) below, plus KYC
| (submit/status) and the admin approval queue. MFA (verify-otp) is still
| a separate follow-up slice — not included yet.
|
| Phase 2: read-only wallet endpoint for the dashboard, plus the admin
| wallet list/ledger/credit/debit routes.
|
| Phase 3: P2P transfer (POST /transfers) and transaction history
| (GET /transactions), both on top of the same WalletService.
|
| Phase 4: FX currency list, live quote, and executing a conversion
| between two currency sub-balances of the caller's own wallet, all on
| top of WalletService::convert().
|
| Phase 5: wallet funding/withdrawal against an external payment rail
| (POST /wallet/fund, POST /wallet/withdraw, GET /wallet/rail-transactions),
| plus the public webhook endpoint a rail calls back on. See
| PaymentRailService for how funding/withdrawal are finalized.
|
| Phase 6: virtual card issuance/management (issue, list, freeze/
| unfreeze, spend limit, on-demand PAN/CVV reveal). Real-time transaction
| authorization against the linked wallet balance is a follow-up phase —
| see CardService's docblock.
|
| Phase 7: bill payments (GET /bills/categories, GET /bills/billers,
| POST /bills/pay, GET /bills/history), plus the public webhook a biller
| provider calls back on. Same debit-then-reverse-on-failure pattern as
| Phase 5's withdrawals — see BillPaymentService.
|
| Phase 8 (hardening): rate limiting via throttle: on auth endpoints
| (brute-force protection) and every money-moving endpoint (baseline
| abuse protection — NOT a substitute for FraudService's velocity checks,
| which are business-rule-aware and per-action; throttle: is a blunt,
| IP/user-agnostic-ish safety net underneath that). 'throttle' is a
| Laravel-default middleware alias — unlike 'admin' below, it needs no
| extra registration.
|
| Phase 9: savings goals (create/deposit/withdraw, interest accrual via
| SavingsService) and micro-loans (rules-based scoring + auto-disburse +
| repay, via LoanService/LoanScoringService). "Public launch" and
| "country 2 vendor research" from the blueprint's own Phase 9 aren't
| code — see the chat response that shipped this phase for what's
| actually still open there.
|
| Phase 10+ will add agent network/scale features under this file,
| grouped by module.
|
*/

Route::get('/health', function () {
    return response()->json([
        'status'  => 'ok',
        'service' => 'ZolvaPay API',
        'time'    => now()->toIso8601String(),
    ]);
});

// Public: a payment rail calls this back, it isn't a logged-in ZolvaPay
// user. Authenticated instead via PaymentRailAdapterInterface::
// verifyWebhookSignature() inside PaymentRailWebhookController.
Route::post('/webhooks/payment-rails/{rail}', [PaymentRailWebhookController::class, 'handle']);

// Same reasoning as the payment-rails webhook above — a biller provider
// calls this back, authenticated via signature rather than a session.
Route::post('/webhooks/billers/{provider}', [BillWebhookController::class, 'handle']);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::prefix('kyc')->group(function () {
        Route::post('/submit', [KycController::class, 'submit']);
        Route::get('/status', [KycController::class, 'status']);
    });

    Route::get('/wallet', [WalletController::class, 'show']);

    Route::post('/transfers', [TransferController::class, 'store'])->middleware('throttle:15,1');
    Route::get('/transactions', [TransferController::class, 'index']);

    Route::prefix('fx')->group(function () {
        Route::get('/currencies', [FxController::class, 'currencies']);
        Route::post('/quote', [FxController::class, 'quote']);
        Route::post('/convert', [FxController::class, 'convert'])->middleware('throttle:15,1');
    });

    Route::post('/wallet/fund', [PaymentRailController::class, 'fund'])->middleware('throttle:15,1');
    Route::post('/wallet/withdraw', [PaymentRailController::class, 'withdraw'])->middleware('throttle:15,1');
    Route::get('/wallet/rail-transactions', [PaymentRailController::class, 'index']);

    Route::prefix('cards')->group(function () {
        Route::get('/', [CardController::class, 'index']);
        Route::post('/', [CardController::class, 'store'])->middleware('throttle:15,1');
        Route::post('/{card}/freeze', [CardController::class, 'freeze']);
        Route::post('/{card}/unfreeze', [CardController::class, 'unfreeze']);
        Route::patch('/{card}/limits', [CardController::class, 'setLimit']);
        Route::post('/{card}/reveal', [CardController::class, 'reveal'])->middleware('throttle:10,1');
    });

    Route::prefix('bills')->group(function () {
        Route::get('/categories', [BillController::class, 'categories']);
        Route::get('/billers', [BillController::class, 'billers']);
        Route::post('/pay', [BillController::class, 'pay'])->middleware('throttle:15,1');
        Route::get('/history', [BillController::class, 'index']);
    });

    Route::prefix('savings/goals')->group(function () {
        Route::get('/', [SavingsController::class, 'index']);
        Route::post('/', [SavingsController::class, 'store']);
        Route::post('/{goal}/deposit', [SavingsController::class, 'deposit'])->middleware('throttle:15,1');
        Route::post('/{goal}/withdraw', [SavingsController::class, 'withdraw'])->middleware('throttle:15,1');
    });

    Route::prefix('loans')->group(function () {
        Route::get('/', [LoanController::class, 'index']);
        Route::post('/apply', [LoanController::class, 'apply'])->middleware('throttle:5,1');
        Route::post('/{loan}/repay', [LoanController::class, 'repay'])->middleware('throttle:15,1');
    });

    // Requires the 'admin' middleware alias to be registered in
    // bootstrap/app.php — see EnsureUserIsAdmin's docblock.
    Route::prefix('admin/kyc')->middleware('admin')->group(function () {
        Route::get('/', [KycAdminController::class, 'index']);
        Route::post('/{kycRecord}/approve', [KycAdminController::class, 'approve']);
        Route::post('/{kycRecord}/reject', [KycAdminController::class, 'reject']);
        Route::get('/{kycRecord}/document/{type}', [KycAdminController::class, 'document']);
    });

    Route::prefix('admin/wallets')->middleware('admin')->group(function () {
        Route::get('/', [WalletAdminController::class, 'index']);
        Route::get('/{wallet}/ledger', [WalletAdminController::class, 'ledger']);
        Route::post('/{wallet}/credit', [WalletAdminController::class, 'credit']);
        Route::post('/{wallet}/debit', [WalletAdminController::class, 'debit']);
    });

    Route::prefix('admin/compliance')->middleware('admin')->group(function () {
        Route::get('/transactions.csv', [ComplianceController::class, 'exportTransactions']);
        Route::get('/fraud-alerts', [ComplianceController::class, 'fraudAlerts']);
    });
});



