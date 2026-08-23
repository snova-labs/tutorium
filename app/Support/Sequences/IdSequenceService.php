<?php

declare(strict_types=1);

namespace App\Support\Sequences;

use App\Models\IdSequence;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Issues business identifiers from tenant-configurable sequences.
 *
 * No identifier format exists anywhere in code. A tenant decides that learners are numbered
 * ACME-KTM-STU-0007 or simply S006, per brand or per branch, and can set the starting number to
 * continue whatever numbering they used before — which removes the usual objection to switching
 * systems (SL-DAT-003 §9).
 */
final class IdSequenceService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $context,
    ) {}

    /**
     * Issue the next identifier for an entity.
     *
     * Must be called inside the transaction that creates the record, so a rolled-back write does
     * not consume a number invisibly.
     *
     * @param string $entity learner | enrollment | assessment | report | invoice | ...
     * @param string $scopeType tenant | brand | branch
     */
    public function next(string $entity, string $scopeType = 'tenant', ?int $scopeId = null): string
    {
        $tenantId = $this->context->require()->getKey();
        $sequence = $this->sequence($tenantId, $entity, $scopeType, $scopeId);

        $number = $this->increment($sequence);

        return $this->format($sequence, $number);
    }

    /**
     * Preview the next identifier without consuming it. For settings screens only — never for
     * assigning an identifier, because two callers would receive the same value.
     */
    public function peek(string $entity, string $scopeType = 'tenant', ?int $scopeId = null): string
    {
        $tenantId = $this->context->require()->getKey();
        $sequence = $this->sequence($tenantId, $entity, $scopeType, $scopeId);

        return $this->format($sequence, $sequence->next_number);
    }

    public function format(IdSequence $sequence, int $number): string
    {
        return $sequence->prefix
            .$sequence->separator
            .str_pad((string) $number, $sequence->pad_width, '0', STR_PAD_LEFT);
    }

    /**
     * Atomically consume the next number.
     *
     * LAST_INSERT_ID(expr) lets a single UPDATE both increment and return the value it used,
     * which is race-free without locking the row for the length of the surrounding transaction.
     */
    private function increment(IdSequence $sequence): int
    {
        $affected = $this->db->update(
            'UPDATE id_sequences SET next_number = LAST_INSERT_ID(next_number + 1), updated_at = ? WHERE id = ?',
            [now(), $sequence->getKey()],
        );

        if ($affected === 0) {
            throw new RuntimeException("Sequence {$sequence->getKey()} could not be incremented.");
        }

        /** @var array<int, object{value: int}> $result */
        $result = $this->db->select('SELECT LAST_INSERT_ID() AS value');

        return (int) $result[0]->value;
    }

    private function sequence(int $tenantId, string $entity, string $scopeType, ?int $scopeId): IdSequence
    {
        return $this->context->withoutScoping(
            fn () => IdSequence::query()
                ->where('tenant_id', $tenantId)
                ->where('entity', $entity)
                ->where('scope_type', $scopeType)
                ->where('scope_id', $scopeId)
                ->where('is_active', true)
                ->first()
                ?? $this->fallback($tenantId, $entity, $scopeType, $scopeId),
        );
    }

    /**
     * A tenant that has not configured a sequence for an entity still gets working identifiers.
     * Onboarding presets normally create these, but the product must never fail to save a record
     * because a settings row is missing.
     */
    private function fallback(int $tenantId, string $entity, string $scopeType, ?int $scopeId): IdSequence
    {
        $defaults = config("sequences.defaults.{$entity}", config('sequences.defaults.fallback'));

        return IdSequence::query()->create([
            'tenant_id' => $tenantId,
            'entity' => $entity,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'prefix' => $defaults['prefix'],
            'separator' => $defaults['separator'],
            'pad_width' => $defaults['pad_width'],
            'next_number' => $defaults['start'],
            'is_active' => true,
        ]);
    }
}
