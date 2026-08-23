<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Brand;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Brand::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required', 'string', 'max:32', 'alpha_dash',
                // Codes are unique per account, never globally — two academies may both have
                // a brand called MAIN, and neither should be told the other exists.
                Rule::unique('brands', 'code')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->whereNull('deleted_at'),
            ],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'sender_email' => ['nullable', 'email', 'max:190'],
            'locale' => ['nullable', 'string', 'max:12'],
            'colors' => ['nullable', 'array'],
            'colors.primary' => ['nullable', 'string', 'max:9'],
            'colors.accent' => ['nullable', 'string', 'max:9'],
            'is_default' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'You already have a brand with this code. Choose another.',
            'code.alpha_dash' => 'Use letters, numbers, dashes and underscores only.',
        ];
    }
}
