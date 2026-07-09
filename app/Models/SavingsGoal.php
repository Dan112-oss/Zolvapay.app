<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsGoal extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'wallet_id',
        'name',
        'currency_code',
        'target_amount_minor',
        'current_amount_minor',
        'interest_rate_bps',
        'target_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'target_amount_minor' => 'integer',
            'current_amount_minor' => 'integer',
            'interest_rate_bps' => 'integer',
            'target_date' => 'date',
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
