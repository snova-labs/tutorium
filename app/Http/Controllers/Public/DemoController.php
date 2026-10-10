<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Jobs\RunDemoJob;
use App\Services\DemoSuite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The demo switch behind the staff client's /demo page: load the demo academies, see how it is
 * going, and remove them again.
 *
 * Public, so it works before anyone has an account, and therefore guarded three ways: it does not
 * exist on production, it does not exist until DEMO_PASSWORD is set, and every change needs that
 * password. It reads and writes only the demo academies.
 */
final class DemoController
{
    public function __construct(private readonly DemoSuite $demo) {}

    public function show(): JsonResponse
    {
        abort_unless($this->demo->enabled(), 404);

        $status = $this->demo->status();

        return response()->json(['data' => $status + [
            // Who to sign in as. The password is the one entered to load the demo.
            'academies' => $status['state'] === 'ready' ? $this->demo->accounts() : [],
        ]]);
    }

    public function build(Request $request): JsonResponse
    {
        return $this->run($request, 'build', 'Loading the demo academies. This takes a minute or two.');
    }

    public function remove(Request $request): JsonResponse
    {
        return $this->run($request, 'remove', 'Removing the demo academies.');
    }

    private function run(Request $request, string $action, string $message): JsonResponse
    {
        abort_unless($this->demo->enabled(), 404);

        $password = (string) $request->validate(['password' => ['required', 'string', 'max:200']])['password'];

        if (! $this->demo->checkPassword($password)) {
            throw ValidationException::withMessages(['password' => 'That is not the demo password.']);
        }

        if ($this->demo->busy()) {
            throw ValidationException::withMessages(['password' => 'The demo is already being loaded or removed. Wait for it to finish.']);
        }

        $this->demo->starting($action === 'build' ? 'building' : 'removing');
        RunDemoJob::dispatch($action, $password);

        return response()->json(['data' => ['message' => $message]], 202);
    }
}
