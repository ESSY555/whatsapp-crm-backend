<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BroadcastRecipient extends Model
{
    use BelongsToBusiness;

    public const STATUSES = ['pending', 'sent', 'delivered', 'read', 'failed'];

    protected $fillable = ['broadcast_id', 'customer_id', 'status', 'message_id', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function broadcast(): BelongsTo { return $this->belongsTo(Broadcast::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function message(): BelongsTo { return $this->belongsTo(Message::class); }

    public function scopePending($query) { return $query->where('status', 'pending'); }
}
