<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Answers "who is doing this, and from where" for the audit trail.
 *
 * Support access is deliberately resolved here rather than at each call site: an operator acting
 * through impersonation must appear in the tenant's own log as an operator, never disguised as
 * the user they are impersonating (SL-SEC-004 §6, FR-AUD-3).
 */
final class AuditContext
{
    private ?string $overrideActorType = null;

    private ?int $overrideActorId = null;

    private ?string $overrideActorName = null;

    /** Called by the impersonation flow so subsequent writes are attributed to the operator. */
    public function actingAsOperator(int $operatorId, string $name): void
    {
        $this->overrideActorType = AuditLog::ACTOR_OPERATOR;
        $this->overrideActorId = $operatorId;
        $this->overrideActorName = $name;
    }

    /** Called by scheduled work: session generation, metering, report runs. */
    public function actingAsSystem(string $name = 'System'): void
    {
        $this->overrideActorType = AuditLog::ACTOR_SYSTEM;
        $this->overrideActorId = null;
        $this->overrideActorName = $name;
    }

    /** @return array{actor_type: string, actor_id: ?int, actor_name: ?string, ip: ?string} */
    public function describe(): array
    {
        if ($this->overrideActorType !== null) {
            return [
                'actor_type' => $this->overrideActorType,
                'actor_id' => $this->overrideActorId,
                'actor_name' => $this->overrideActorName,
                'ip' => $this->ip(),
            ];
        }

        $user = Auth::user();

        if ($user !== null) {
            return [
                'actor_type' => AuditLog::ACTOR_USER,
                'actor_id' => $user->getKey(),
                'actor_name' => $user->name,
                'ip' => $this->ip(),
            ];
        }

        return [
            'actor_type' => AuditLog::ACTOR_SYSTEM,
            'actor_id' => null,
            'actor_name' => 'System',
            'ip' => null,
        ];
    }

    private function ip(): ?string
    {
        if (! app()->bound(Request::class)) {
            return null;
        }

        return app(Request::class)->ip();
    }
}
