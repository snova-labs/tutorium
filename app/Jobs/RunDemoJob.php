<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\DemoSuite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Builds or removes the demo academies, away from the request that asked for it: a build takes a
 * minute or two, longer than any proxy waits for a page.
 */
final class RunDemoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Long enough for four academies on a small ARM server. */
    public int $timeout = 1800;

    /** Never re-run a half-finished build: the status says it failed, and a person tries again. */
    public int $tries = 1;

    public function __construct(
        public readonly string $action,
        // The password every demo account gets. Held in the queue for minutes, on staging only.
        #[\SensitiveParameter] public readonly string $password = '',
    ) {}

    public function handle(DemoSuite $demo): void
    {
        $this->action === 'remove'
            ? $demo->remove()
            : $demo->build($this->password, fresh: true);
    }
}
