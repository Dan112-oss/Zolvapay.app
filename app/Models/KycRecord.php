<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycRecord extends Model
{
    use HasUuids;

    /**
     * The primary key is a UUID string, not an auto-incrementing integer.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'tier',
        'document_type',
        'document_number_hash',
        'document_front_path',
        'document_back_path',
        'selfie_path',
        'verification_status',
        'provider',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'submitted_at',
        'verified_at',
    ];

    /**
     * File paths point to a private disk and the hash has no legitimate
     * use client-side — never expose these outside the admin document
     * endpoint, which streams the file itself rather than the path.
     *
     * @var list<string>
     */
    protected $hidden = [
        'document_front_path',
        'document_back_path',
        'selfie_path',
        'document_number_hash',
    ];

    /**
     * Lets the admin queue UI know whether to show a "View back" link,
     * without exposing the actual storage path (that stays hidden — see
     * $hidden above and KycAdminController::document()).
     *
     * @var list<string>
     */
    protected $appends = ['has_document_back'];

    public function getHasDocumentBackAttribute(): bool
    {
        return ! empty($this->document_back_path);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('verification_status', 'pending');
    }
}
