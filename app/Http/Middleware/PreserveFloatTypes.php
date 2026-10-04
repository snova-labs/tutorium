<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Floats stay floats in API responses.
 *
 * By default an attendance rate of 100.0 is encoded as 100 while 87.5 stays 87.5, so a client sees
 * the same field change type depending on its value. The guardian and learner portals consume this
 * API, and a percentage should arrive as a number of one kind.
 */
final class PreserveFloatTypes
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Plain response()->json() payloads only. An API resource builds its own envelope when it
        // is turned into a response, so its original object cannot simply be encoded again.
        if ($response instanceof JsonResponse && is_array($response->original)) {
            // Re-encode from the original data: the already-encoded body has lost the fraction.
            $original = $response->original;
            $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRESERVE_ZERO_FRACTION);
            $response->setData($original);
        }

        return $response;
    }
}
