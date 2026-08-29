<?php

namespace App\Support;

use App\Models\Tenant;

class TenantContext
{
    protected static ?Tenant $tenant = null;
    protected static bool $bypass = false;

    /**
     * Set the current active tenant.
     */
    public static function setTenant(?Tenant $tenant): void
    {
        static::$tenant = $tenant;
    }

    /**
     * Get the current active tenant.
     */
    public static function getTenant(): ?Tenant
    {
        return static::$tenant;
    }

    /**
     * Get the current active tenant ID.
     */
    public static function getTenantId(): ?int
    {
        return static::$tenant?->id;
    }

    /**
     * Check if a tenant is currently resolved and active.
     */
    public static function check(): bool
    {
        return static::$tenant !== null;
    }

    /**
     * Clear the current tenant context.
     */
    public static function forgetTenant(): void
    {
        static::$tenant = null;
        static::$bypass = false;
    }

    /**
     * Enable or disable tenant scoping bypass (e.g. for Super Admin or global operations).
     */
    public static function setBypass(bool $bypass = true): void
    {
        static::$bypass = $bypass;
    }

    /**
     * Check if tenant scoping bypass is enabled.
     */
    public static function isBypassed(): bool
    {
        return static::$bypass;
    }

    /**
     * Execute a callback without tenant scoping.
     */
    public static function withoutTenancy(callable $callback): mixed
    {
        $previousBypass = static::$bypass;
        static::$bypass = true;

        try {
            return $callback();
        } finally {
            static::$bypass = $previousBypass;
        }
    }
}
