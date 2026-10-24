<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Business extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'logo', 'email', 'phone', 'address',
        'city', 'state', 'country', 'currency', 'timezone',
        'tax_number', 'status', 'trial_ends_at',
    ];

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_users')
            ->withPivot('role', 'status')
            ->withTimestamps();
    }

    public function settings(): HasMany
    {
        return $this->hasMany(BusinessSetting::class);
    }

    public function whatsappConnections(): HasMany
    {
        return $this->hasMany(WhatsAppConnection::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function wallet(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function topupRequests(): HasMany
    {
        return $this->hasMany(TopupRequest::class);
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings->firstWhere('key', $key)?->value ?? $default;
    }
}
