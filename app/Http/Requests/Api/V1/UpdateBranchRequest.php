<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('branch'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => [
                'sometimes', 'string', 'max:32', 'alpha_dash',
                Rule::unique('branches', 'code')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($this->route('branch')?->getKey())
                    ->whereNull('deleted_at'),
            ],
            'timezone' => ['sometimes', 'timezone:all'],
            'week_start' => ['nullable', Rule::in($days)],
            'weekend_days' => ['nullable', 'array', 'max:3'],
            'weekend_days.*' => [Rule::in($days)],
            'address' => ['nullable', 'array'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'],
            'is_active' => ['boolean'],
        ];
    }
}
