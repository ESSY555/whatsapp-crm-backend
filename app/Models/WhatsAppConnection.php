<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppConnection extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['phone_number_id', 'business_account_id', 'access_token_encrypted', 'status', 'connected_at', 'last_verified_at'];
    protected $hidden = ['access_token_encrypted'];
    protected function casts(): array { return ['access_token_encrypted' => 'encrypted', 'connected_at' => 'datetime', 'last_verified_at' => 'datetime']; }
    public function conversations(): HasMany { return $this->hasMany(Conversation::class); }
}
