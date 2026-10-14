<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ReportTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Report templates: what a report carries, and which reports it applies to.
 *
 * Resolution is course → brand → the account default, so the one rule worth enforcing here is
 * that exactly one template is the default. Without it, an academy's reports stop being
 * predictable: which template answered last is whichever row the database handed back first.
 */
final class ReportTemplateService
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): ReportTemplate
    {
        return DB::transaction(function () use ($attributes): ReportTemplate {
            $template = ReportTemplate::query()->create($attributes);

            // The first template of an account is its default, without anyone having to decide.
            if (ReportTemplate::query()->count() === 1) {
                $template->forceFill(['is_default' => true])->save();
            } elseif ($template->is_default) {
                $this->demoteOthers($template);
            }

            return $template->refresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(ReportTemplate $template, array $attributes): ReportTemplate
    {
        return DB::transaction(function () use ($template, $attributes): ReportTemplate {
            $template->update($attributes);

            if ($template->is_default) {
                $this->demoteOthers($template);
            } elseif (! ReportTemplate::query()->where('is_default', true)->exists()) {
                // Nothing may leave the account with no default: reports would then render from
                // whichever template the database happened to return.
                $template->forceFill(['is_default' => true])->save();
            }

            return $template->refresh();
        });
    }

    /**
     * What a report rendered from this template keeps, so a re-download matches what was sent.
     *
     * @return array{blocks: array<int, string>, closing: string|null, locale: string|null}
     */
    public function frozen(ReportTemplate $template): array
    {
        return [
            'blocks' => $template->blocks ?? [],
            'closing' => $template->closing,
            'locale' => $template->locale,
        ];
    }

    private function demoteOthers(ReportTemplate $template): void
    {
        ReportTemplate::query()
            ->whereKeyNot($template->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
