<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Broadcast extends Model
{
    use BelongsToBusiness;

    public const STATUSES = ['draft', 'scheduled', 'processing', 'completed', 'failed', 'paused'];

    protected $fillable = [
        'name', 'template_id', 'content', 'status', 'total_recipients', 'sent_count', 'delivered_count',
        'failed_count', 'scheduled_at', 'started_at', 'completed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
            'total_recipients' => 'integer', 'sent_count' => 'integer',
            'delivered_count' => 'integer', 'failed_count' => 'integer',
        ];
    }

    public function template(): BelongsTo { return $this->belongsTo(MessageTemplate::class); }
    public function recipients(): HasMany { return $this->hasMany(BroadcastRecipient::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeDue($query)
    {
        return $query->where('status', 'scheduled')->where('scheduled_at', '<=', now());
    }

    public function pendingCount(): int
    {
        return max(0, $this->total_recipients - $this->sent_count - $this->failed_count);
    }

    public function stats(): array
    {
        $total = max($this->total_recipients, 0);
        $rate = fn (int $n) => $total > 0 ? round($n / $total * 100, 2) : 0.0;

        return [
            'total_recipients' => $total,
            'sent_count' => $this->sent_count,
            'delivered_count' => $this->delivered_count,
            'failed_count' => $this->failed_count,
            'pending_count' => $this->pendingCount(),
            'delivery_rate' => $rate($this->delivered_count),
            'failure_rate' => $rate($this->failed_count),
        ];
    }
}
