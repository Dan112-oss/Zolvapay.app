<?php

namespace App\Http\Controllers\Card;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Services\Cards\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CardController extends Controller
{
    public function __construct(
        private readonly CardService $cardService,
    ) {
    }

    /**
     * POST /api/cards
     * Body: currency_code, cardholder_name?
     *
     * cardholder_name defaults to the user's own profile name — allowed
     * to be overridden (e.g. a shortened printable form) but never
     * required.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->kyc_tier || $user->kyc_tier < 1) {
            return response()->json([
                'message' => 'Complete Tier 1 verification before issuing a card.',
            ], 403);
        }

        $supported = config('fx.supported_currencies', []);

        $validator = Validator::make($request->all(), [
            'currency_code' => ['required', 'string', 'size:3', 'in:'.implode(',', $supported)],
            'cardholder_name' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $card = $this->cardService->issueCard(
            user: $user,
            currencyCode: strtoupper($validated['currency_code']),
            cardholderName: $validated['cardholder_name'] ?? $user->name,
        );

        return response()->json([
            'message' => 'Card issued.',
            'card' => $card,
        ], 201);
    }

    /**
     * GET /api/cards
     */
    public function index(Request $request): JsonResponse
    {
        $cards = $request->user()->cards()->orderByDesc('created_at')->get();

        return response()->json(['cards' => $cards]);
    }

    public function freeze(Request $request, Card $card): JsonResponse
    {
        $denied = $this->denyIfNotOwner($request, $card);
        if ($denied) {
            return $denied;
        }

        return response()->json([
            'message' => 'Card frozen.',
            'card' => $this->cardService->freeze($card),
        ]);
    }

    public function unfreeze(Request $request, Card $card): JsonResponse
    {
        $denied = $this->denyIfNotOwner($request, $card);
        if ($denied) {
            return $denied;
        }

        return response()->json([
            'message' => 'Card unfrozen.',
            'card' => $this->cardService->unfreeze($card),
        ]);
    }

    /**
     * PATCH /api/cards/{card}/limits
     * Body: spend_limit (major units) — omit or send null to remove any
     * existing limit.
     */
    public function setLimit(Request $request, Card $card): JsonResponse
    {
        $denied = $this->denyIfNotOwner($request, $card);
        if ($denied) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'spend_limit' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $spendLimit = $validator->validated()['spend_limit'] ?? null;
        $limitMinor = $spendLimit !== null ? (int) round($spendLimit * 100) : null;

        return response()->json([
            'message' => $limitMinor === null ? 'Spend limit removed.' : 'Spend limit updated.',
            'card' => $this->cardService->setSpendLimit($card, $limitMinor),
        ]);
    }

    /**
     * POST /api/cards/{card}/reveal
     *
     * Returns full PAN/CVV for this single request only — nothing here
     * is ever persisted (see CardService::reveal()'s docblock). Frontend
     * should show these briefly and never store them client-side beyond
     * the current page view either.
     */
    public function reveal(Request $request, Card $card): JsonResponse
    {
        $denied = $this->denyIfNotOwner($request, $card);
        if ($denied) {
            return $denied;
        }

        if ($card->status !== 'active') {
            return response()->json(['message' => 'Only an active card\'s details can be revealed.'], 409);
        }

        return response()->json([
            'details' => $this->cardService->reveal($card),
        ]);
    }

    private function denyIfNotOwner(Request $request, Card $card): ?JsonResponse
    {
        if ($card->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Card not found.'], 404);
        }

        return null;
    }
}
