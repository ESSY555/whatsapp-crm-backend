<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToBusiness;
    protected $fillable = ['conversation_id', 'customer_id', 'direction', 'type', 'body', 'whatsapp_message_id', 'status', 'error_message', 'sent_at', 'delivered_at', 'read_at'];
    protected function casts(): array { return ['sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime']; }
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}
