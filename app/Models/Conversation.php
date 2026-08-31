<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use BelongsToBusiness;
    protected $fillable = ['customer_id', 'whatsapp_connection_id', 'status', 'last_message_at'];
    protected function casts(): array { return ['last_message_at' => 'datetime']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function connection(): BelongsTo { return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id'); }
    public function messages(): HasMany { return $this->hasMany(Message::class); }
}
