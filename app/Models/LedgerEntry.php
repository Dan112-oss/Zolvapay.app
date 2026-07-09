<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. Rows are never updated or deleted after creation — see the
 * migration's docblock. Application code must never call ->update() or
 * ->delete() on a LedgerEntry; corrections happen via a new reversing
 * entry (not built yet).
 */
class LedgerEntry extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * No updated_at — this table is append-only.
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'transaction_id',
        'wallet_id',
        'currency_code',
        'entry_type',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
