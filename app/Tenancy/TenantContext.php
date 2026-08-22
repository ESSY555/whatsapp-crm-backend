<?php

namespace App\Tenancy;

class TenantContext
{
    protected ?int $businessId = null;

    public function set(int $businessId): void
    {
        $this->businessId = $businessId;
    }

    public function get(): ?int
    {
        return $this->businessId;
    }

    public function clear(): void
    {
        $this->businessId = null;
    }

    public function has(): bool
    {
        return $this->businessId !== null;
    }
}
