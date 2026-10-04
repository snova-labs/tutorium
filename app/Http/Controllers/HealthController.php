<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Probes for the load balancer and the uptime monitor.
 *
 * Two questions, kept apart. "Is the process up" never touches a dependency, so a database blip
 * does not get a healthy container restarted. "Can it serve traffic" checks what a request actually
 * needs, and says which part failed without saying why — this endpoint is public.
 */
final class HealthController
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::connection()->getPdo()),
            'cache' => $this->check(fn () => Cache::get('health:probe')),
        ];

        $ready = ! in_array(false, $checks, true);

        return response()->json(
            ['status' => $ready ? 'ok' : 'unavailable', 'checks' => $checks],
            $ready ? 200 : 503,
        );
    }

    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable $e) {
            Log::warning('Readiness check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
