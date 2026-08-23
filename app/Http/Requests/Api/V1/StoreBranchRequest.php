<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Branch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Branch::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        return [
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')],
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required', 'string', 'max:32', 'alpha_dash',
                Rule::unique('branches', 'code')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->whereNull('deleted_at'),
            ],
            // Rejecting anything that is not a real IANA identifier here is what stops a typo
            // from silently mis-scheduling every session under this branch.
            'timezone' => ['required', 'timezone:all'],
            'week_start' => ['nullable', Rule::in($days)],
            'weekend_days' => ['nullable', 'array', 'max:3'],
            'weekend_days.*' => [Rule::in($days)],
            'address' => ['nullable', 'array'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'timezone.timezone' => 'Use a timezone name such as Asia/Kathmandu or America/Toronto.',
            'code.unique' => 'You already have a branch with this code.',
        ];
    }
}
