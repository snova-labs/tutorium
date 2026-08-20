<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        // A leaked context between tests hides isolation bugs by making everything look scoped.
        if ($this->app !== null) {
            app(TenantContext::class)->forget();
        }

        parent::tearDown();
    }
}
