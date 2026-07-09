<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * initiator_wallet_id: the wallet whose owner the transaction is
     * conceptually "for" (the sender in a transfer, the wallet being
     * topped up/withdrawn from in an admin adjustment).
     * counterparty_wallet_id: the other side (the recipient, or the
     * system wallet for an admin adjustment).
     */
    protected $fillable = [
        'type',
        'status',
        'initiator_wallet_id',
        'counterparty_wallet_id',
        'amount',
        'currency_code',
        'fee',
        'metadata',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee' => 'integer',
            'metadata' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function initiatorWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'initiator_wallet_id');
    }

    public function counterpartyWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'counterparty_wallet_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
