<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Authenticate through Sanctum so the request carries a (transient)
     * access token, exactly like a real bearer-token request would.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        Sanctum::actingAs($user);

        return $this;
    }
}
