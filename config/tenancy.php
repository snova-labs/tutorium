<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\IdSequence;
use App\Models\Setting;
use App\Models\User;

return [

    /*
    |---------------------------------------------------------------------------
    | Tenant resource registry
    |---------------------------------------------------------------------------
    |
    | Every tenant-owned model MUST be listed here. TenantRegistryTest scans
    | app/Models, finds every model using BelongsToTenant, and fails the build if
    | one is missing — so adding a resource without isolation coverage is not an
    | oversight that can reach production, it is a red pipeline.
    |
    | Conversely, a model listed here that does not use the trait also fails.
    |
    */

    'resources' => [
        Brand::class,
        Branch::class,
        User::class,
        Setting::class,
        IdSequence::class,
        AuditLog::class,
    ],

    /*
    |---------------------------------------------------------------------------
    | Models exempt from tenant ownership
    |---------------------------------------------------------------------------
    |
    | Control-plane and global lookup models. Listing one here is a deliberate
    | statement that it holds no tenant data — it is reviewed like a security
    | change, because a mistake here is a leak.
    |
    */

    'global_models' => [
        \App\Models\Tenant::class,
    ],

];
