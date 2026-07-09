<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycRecord;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KycAdminController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }
    /**
     * GET /api/admin/kyc?status=pending
     *
     * Defaults to the pending queue (what an admin actually needs to work
     * through day to day). Pass ?status=approved or ?status=rejected to
     * review history instead.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');

        $records = KycRecord::with('user:id,name,email,country_code')
            ->where('verification_status', $status)
            ->orderBy('submitted_at')
            ->paginate(20);

        return response()->json($records);
    }

    /**
     * POST /api/admin/kyc/{kycRecord}/approve
     */
    public function approve(Request $request, KycRecord $kycRecord): JsonResponse
    {
        if ($kycRecord->verification_status !== 'pending') {
            return response()->json([
                'message' => 'Only pending submissions can be approved.',
            ], 409);
        }

        $kycRecord->update([
            'verification_status' => 'approved',
            'verified_at' => now(),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);

        $kycRecord->user->update(['kyc_tier' => $kycRecord->tier]);

        $this->auditLogService->record($request->user()->id, 'kyc.approved', 'KycRecord', $kycRecord->id, $request);

        return response()->json([
            'message' => 'KYC submission approved.',
            'kyc' => $kycRecord->fresh(),
        ]);
    }

    /**
     * POST /api/admin/kyc/{kycRecord}/reject
     */
    public function reject(Request $request, KycRecord $kycRecord): JsonResponse
    {
        if ($kycRecord->verification_status !== 'pending') {
            return response()->json([
                'message' => 'Only pending submissions can be rejected.',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $kycRecord->update([
            'verification_status' => 'rejected',
            'rejection_reason' => $validator->validated()['reason'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $this->auditLogService->record($request->user()->id, 'kyc.rejected', 'KycRecord', $kycRecord->id, $request);

        return response()->json([
            'message' => 'KYC submission rejected.',
            'kyc' => $kycRecord->fresh(),
        ]);
    }

    /**
     * GET /api/admin/kyc/{kycRecord}/document/{type}
     *
     * Streams an uploaded document straight from the private disk. Never
     * exposes a public URL or the raw storage path — this endpoint is the
     * only way to view the file, and it's gated by the 'admin' middleware
     * same as the rest of this controller.
     */
    public function document(KycRecord $kycRecord, string $type): StreamedResponse|JsonResponse
    {
        $path = match ($type) {
            'front' => $kycRecord->document_front_path,
            'back' => $kycRecord->document_back_path,
            'selfie' => $kycRecord->selfie_path,
            default => null,
        };

        if (! $path || ! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        return Storage::disk('local')->response($path);
    }
}
