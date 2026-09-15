<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderLog extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['reminder_rule_id', 'invoice_id', 'customer_id', 'message_id', 'status', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function rule(): BelongsTo { return $this->belongsTo(ReminderRule::class, 'reminder_rule_id'); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function message(): BelongsTo { return $this->belongsTo(Message::class); }

    public function scopeSent($query) { return $query->where('status', 'sent'); }
    public function scopeFailed($query) { return $query->where('status', 'failed'); }
}
