<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void set(int $businessId)
 * @method static int|null get()
 * @method static void clear()
 * @method static bool has()
 */
class TenantManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TenantContext::class;
    }

    public static function businessId(): ?int
    {
        return static::get();
    }
}
