<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledMessage extends Model
{
    use BelongsToBusiness;

    public const STATUSES = ['scheduled', 'processing', 'sent', 'failed', 'cancelled'];

    protected $fillable = [
        'customer_id', 'template_id', 'content', 'scheduled_at', 'status', 'sent_at', 'failed_at', 'error_message',
    ];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function template(): BelongsTo { return $this->belongsTo(MessageTemplate::class); }

    public function scopeDue($query)
    {
        return $query->where('status', 'scheduled')->where('scheduled_at', '<=', now());
    }

    public function isPending(): bool
    {
        return $this->status === 'scheduled';
    }
}
