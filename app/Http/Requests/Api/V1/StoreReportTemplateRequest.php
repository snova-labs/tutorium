<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\ReportTemplate;
use App\Support\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreReportTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ReportTemplate::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // A template with no blocks would send a page with a greeting and nothing else.
            'blocks' => ['required', 'array', 'min:1'],
            'blocks.*' => [Rule::in(array_keys((array) config('reporting.blocks')))],
            'closing' => ['nullable', 'string', 'max:190'],
            'locale' => ['nullable', 'string', 'max:12'],
            // Which reports it applies to: a course's reports, a brand's, or the account's.
            'brand_id' => ['nullable', 'integer', TenantRule::exists('brands')],
            'course_id' => ['nullable', 'integer', TenantRule::exists('courses')],
            'is_default' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'blocks.required' => 'Choose at least one section, or the report says nothing.',
            'blocks.*.in' => 'That is not a section a report can carry.',
        ];
    }
}
