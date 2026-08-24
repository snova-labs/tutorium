<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The mechanism that makes tenant isolation structural rather than aspirational.
 *
 * A developer adding a new tenant-owned model cannot forget to cover it: if the model uses
 * BelongsToTenant but is not registered, or is registered but lacks the trait or the column,
 * the build goes red. Isolation coverage stops depending on anyone remembering.
 *
 * @see BelongsToTenant
 * @see config/tenancy.php
 */
final class TenantRegistryTest extends TestCase
{
    #[Test]
    public function every_tenant_owned_model_is_registered(): void
    {
        $registered = config('tenancy.resources');
        $unregistered = [];

        foreach ($this->modelsUsingTenantTrait() as $model) {
            if (! in_array($model, $registered, true)) {
                $unregistered[] = $model;
            }
        }

        $this->assertSame([], $unregistered, sprintf(
            "These models use BelongsToTenant but are not registered in config/tenancy.php:\n  %s\n\n"
            ."Add them to the 'resources' array so the isolation suite covers them.",
            implode("\n  ", $unregistered),
        ));
    }

    #[Test]
    public function every_registered_model_actually_uses_the_trait(): void
    {
        foreach (config('tenancy.resources') as $model) {
            $this->assertContains(
                BelongsToTenant::class,
                class_uses_recursive($model),
                "{$model} is registered as tenant-owned but does not use BelongsToTenant. "
                .'Registration alone applies no scoping — the trait is the control.',
            );
        }
    }

    #[Test]
    public function every_registered_model_has_a_tenant_column(): void
    {
        foreach (config('tenancy.resources') as $model) {
            $instance = new $model;
            $table = $instance->getTable();
            $column = $instance->getTenantColumn();

            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "Table {$table} is missing the {$column} column required by {$model}.",
            );
        }
    }

    #[Test]
    public function global_models_are_declared_deliberately(): void
    {
        $declared = array_merge(
            config('tenancy.resources'),
            config('tenancy.global_models'),
        );

        $undeclared = [];

        foreach ($this->allModels() as $model) {
            if (! in_array($model, $declared, true)) {
                $undeclared[] = $model;
            }
        }

        $this->assertSame([], $undeclared, sprintf(
            "These models are neither tenant-owned nor declared global:\n  %s\n\n"
            .'Every model must make a deliberate statement about whether it holds tenant data. '
            .'Declaring one global is reviewed like a security change, because a mistake is a leak.',
            implode("\n  ", $undeclared),
        ));
    }

    /** @return array<int, class-string> */
    private function modelsUsingTenantTrait(): array
    {
        return array_values(array_filter(
            $this->allModels(),
            fn (string $model) => in_array(BelongsToTenant::class, class_uses_recursive($model), true),
        ));
    }

    /** @return array<int, class-string> */
    private function allModels(): array
    {
        $models = [];

        foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
            $class = $this->classFromFile($file);

            if ($class === null) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $models[] = $class;
        }

        sort($models);

        return $models;
    }

    /** @return class-string|null */
    private function classFromFile(SplFileInfo $file): ?string
    {
        $relative = Str::of($file->getRealPath())
            ->after(realpath(app_path()).DIRECTORY_SEPARATOR)
            ->replace(DIRECTORY_SEPARATOR, '\\')
            ->replace('.php', '')
            ->toString();

        $class = 'App\\'.$relative;

        return class_exists($class) ? $class : null;
    }
}
