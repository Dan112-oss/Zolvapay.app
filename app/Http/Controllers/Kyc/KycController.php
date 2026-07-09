<?php

namespace App\Http\Controllers\Kyc;

use App\Http\Controllers\Controller;
use App\Models\KycRecord;
use App\Services\Kyc\KycProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class KycController extends Controller
{
    /**
     * POST /api/kyc/submit
     *
     * Tier 1 KYC: basic ID document + selfie upload. Documents are stored
     * on the 'local' (private) disk — never public/ — and are only ever
     * served back out through the admin document-viewing endpoint, which
     * checks admin auth before streaming the file.
     */
    public function submit(Request $request): JsonResponse
    {
        $user = $request->user();

        $existing = KycRecord::where('user_id', $user->id)
            ->whereIn('verification_status', ['pending', 'approved'])
            ->latest('submitted_at')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => $existing->verification_status === 'approved'
                    ? 'You are already verified.'
                    : 'Your last submission is still pending review.',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'document_type' => ['required', 'in:passport,national_id,drivers_license'],
            'document_number' => ['required', 'string', 'max:64'],
            'document_front' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'document_back' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'selfie' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $folder = 'kyc/'.$user->id;

        $frontPath = $request->file('document_front')->store($folder, 'local');
        $backPath = $request->hasFile('document_back')
            ? $request->file('document_back')->store($folder, 'local')
            : null;
        $selfiePath = $request->file('selfie')->store($folder, 'local');

        $kycRecord = KycRecord::create([
            'user_id' => $user->id,
            'tier' => 1,
            'document_type' => $validated['document_type'],
            'document_number_hash' => hash('sha256', $validated['document_number']),
            'document_front_path' => $frontPath,
            'document_back_path' => $backPath,
            'selfie_path' => $selfiePath,
            'verification_status' => 'pending',
            'provider' => config('kyc.provider', 'mock'),
            'submitted_at' => now(),
        ]);

        // Ask the configured provider to verify. The mock provider always
        // comes back 'pending'; a real synchronous vendor could return
        // 'approved'/'rejected' right here.
        $result = KycProviderFactory::make()->verify($kycRecord);

        $kycRecord->verification_status = $result->status;

        if ($result->status === 'approved') {
            $kycRecord->verified_at = now();
            $user->update(['kyc_tier' => $kycRecord->tier]);
        } elseif ($result->status === 'rejected') {
            $kycRecord->rejection_reason = $result->reason;
        }

        $kycRecord->save();

        return response()->json([
            'message' => 'KYC submission received.',
            'kyc' => $kycRecord,
        ], 201);
    }

    /**
     * GET /api/kyc/status
     *
     * Returns the authenticated user's latest KYC submission (if any) plus
     * their current kyc_tier, so the frontend can show the right state
     * (not submitted / pending / approved / rejected).
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        $latest = KycRecord::where('user_id', $user->id)
            ->latest('submitted_at')
            ->first();

        return response()->json([
            'kyc_tier' => $user->kyc_tier,
            'latest' => $latest,
        ]);
    }
}
