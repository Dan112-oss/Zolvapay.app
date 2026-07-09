<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Loan extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'wallet_id',
        'currency_code',
        'principal_minor',
        'interest_rate_bps',
        'outstanding_balance_minor',
        'status',
        'rejection_reason',
        'disbursed_at',
        'due_date',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'principal_minor' => 'integer',
            'interest_rate_bps' => 'integer',
            'outstanding_balance_minor' => 'integer',
            'disbursed_at' => 'datetime',
            'due_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
