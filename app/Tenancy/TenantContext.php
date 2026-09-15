<?php

namespace App\Tenancy;

use Closure;

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

    /**
     * Run a callback with the tenant context temporarily set to the given business.
     * Used by queue jobs and console commands, which have no authenticated user.
     */
    public function runAs(int $businessId, Closure $callback): mixed
    {
        $previous = $this->businessId;
        $this->businessId = $businessId;

        try {
            return $callback();
        } finally {
            $this->businessId = $previous;
        }
    }
}
