<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Brand;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function test_tenant_owned_models_name_their_tenant_column(): void
    {
        $this->assertSame('tenant_id', (new Brand)->getTenantColumn());
    }
}
