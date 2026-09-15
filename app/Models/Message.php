<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToBusiness;

    public const STATUSES = ['queued', 'sending', 'sent', 'delivered', 'read', 'failed'];

    // Delivery statuses only move forward; a late "delivered" webhook must not undo "read".
    public const STATUS_RANK = ['queued' => 0, 'sending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'failed' => 5];

    protected $fillable = [
        'conversation_id', 'customer_id', 'direction', 'type', 'template_id', 'body', 'whatsapp_message_id',
        'status', 'error_message', 'metadata', 'sent_at', 'delivered_at', 'read_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime', 'metadata' => 'array'];
    }

    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function template(): BelongsTo { return $this->belongsTo(MessageTemplate::class, 'template_id'); }

    public function scopeOutbound($query) { return $query->where('direction', 'outbound'); }
    public function scopeInbound($query) { return $query->where('direction', 'inbound'); }
}
