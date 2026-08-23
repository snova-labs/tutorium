<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('brand'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => [
                'sometimes', 'string', 'max:32', 'alpha_dash',
                Rule::unique('brands', 'code')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($this->route('brand')?->getKey())
                    ->whereNull('deleted_at'),
            ],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'sender_email' => ['nullable', 'email', 'max:190'],
            'locale' => ['nullable', 'string', 'max:12'],
            'colors' => ['nullable', 'array'],
            'is_default' => ['boolean'],
        ];
    }
}
