<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'name', 'business_name', 'phone', 'email', 'address', 'category',
        'notes', 'status',
    ];

    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            $customer->phone_normalized = $customer->whatsappNumber();
        });
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(CustomerGroup::class, 'customer_group_members')
            ->withTimestamps();
    }

    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function conversations(): HasMany { return $this->hasMany(Conversation::class); }
    public function messages(): HasMany { return $this->hasMany(Message::class); }

    /**
     * Digits-only phone in international format, as expected by the WhatsApp Cloud API.
     */
    public function whatsappNumber(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->phone);
        return $digits !== '' ? $digits : null;
    }
}
