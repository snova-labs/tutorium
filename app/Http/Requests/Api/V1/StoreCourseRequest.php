<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\PeriodType;
use App\Models\Course;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class StoreCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Course::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')],
            'name' => ['required', 'string', 'max:160'],
            'code' => [
                'required', 'string', 'max:32', 'alpha_dash',
                Rule::unique('courses', 'code')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->whereNull('deleted_at'),
            ],
            'audience' => ['nullable', Rule::in(['kids', 'teens', 'adults', 'corporate'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'period_type' => ['required', new Enum(PeriodType::class)],
            'period_anchor_month' => ['nullable', 'integer', 'between:1,12'],
            'period_block_weeks' => ['nullable', 'integer', 'between:1,52'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'period_type.Illuminate\Validation\Rules\Enum' => 'Choose monthly, term, quarter, block or custom.',
            'code.unique' => 'You already have a course with this code.',
        ];
    }
}
