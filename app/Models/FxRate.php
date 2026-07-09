<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only, like LedgerEntry — a new row is inserted on every refresh
 * or live quote, never updated. See the migration's docblock for why.
 */
class FxRate extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'base_currency',
        'quote_currency',
        'mid_rate',
        'margin_bps',
        'effective_rate',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'mid_rate' => 'decimal:8',
            'margin_bps' => 'integer',
            'effective_rate' => 'decimal:8',
            'fetched_at' => 'datetime',
        ];
    }
}
