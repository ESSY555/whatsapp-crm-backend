<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A business's WhatsApp number on the Cloud API. Normally created by the
 * Embedded Signup flow ("Connect WhatsApp"); the manual form exists only
 * for local development.
 */
class WhatsAppConnection extends Model
{
    use BelongsToBusiness;

    protected $table = 'whatsapp_connections';

    public const SOURCE_EMBEDDED = 'embedded';
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'phone_number_id', 'business_account_id', 'display_phone_number', 'verified_name', 'quality_rating', 'messaging_limit_tier', 'platform_type',
        'access_token_encrypted', 'pin_encrypted', 'status', 'onboarding_source', 'connected_by',
        'connected_at', 'webhook_subscribed_at', 'credit_line_attached_at', 'templates_synced_at', 'last_verified_at', 'last_error',
    ];

    // Secrets are encrypted at rest and never serialized into API responses.
    protected $hidden = ['access_token_encrypted', 'pin_encrypted'];

    protected function casts(): array
    {
        return [
            'access_token_encrypted' => 'encrypted',
            'pin_encrypted' => 'encrypted',
            'connected_at' => 'datetime',
            'webhook_subscribed_at' => 'datetime',
            'credit_line_attached_at' => 'datetime',
            'templates_synced_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isEmbedded(): bool
    {
        return $this->onboarding_source === self::SOURCE_EMBEDDED;
    }

    /** Quality as Meta reports it, normalised for the UI: green / yellow / red / unknown. */
    public function qualityLabel(): string
    {
        return match (strtoupper((string) $this->quality_rating)) {
            'GREEN' => 'green',
            'YELLOW' => 'yellow',
            'RED' => 'red',
            default => 'unknown',
        };
    }
}
