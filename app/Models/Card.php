<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Card extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Never add processor tokens, full PAN, or CVV to $fillable — see
     * the migration's docblock. processor_card_id is set explicitly by
     * CardService after issuance, not mass-assigned from a request.
     */
    protected $fillable = [
        'user_id',
        'wallet_id',
        'currency_code',
        'processor',
        'processor_card_id',
        'masked_pan',
        'last_four',
        'expiry_month',
        'expiry_year',
        'cardholder_name',
        'card_type',
        'status',
        'spend_limit_minor',
        'metadata',
    ];

    /**
     * processor_card_id is intentionally NOT hidden — it's an opaque
     * token, not sensitive by itself — but keeping it out of what the
     * frontend needs means it's fine either way. metadata can contain
     * raw processor responses, which may include fields not meant for
     * the end user, so it's hidden here.
     */
    protected $hidden = [
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'expiry_month' => 'integer',
            'expiry_year' => 'integer',
            'spend_limit_minor' => 'integer',
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
