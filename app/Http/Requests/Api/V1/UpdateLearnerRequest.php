<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateLearnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('learner'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'legal_name' => ['sometimes', 'string', 'max:190'],
            'preferred_name' => ['nullable', 'string', 'max:120'],
            'sort_name' => ['nullable', 'string', 'max:190'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'size:2'],
            'home_timezone' => ['nullable', 'timezone:all'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'status_id' => ['sometimes', 'integer', TenantRule::exists('learner_statuses')],
            'status_reason' => ['nullable', 'string', 'max:255'],
            'custom' => ['nullable', 'array'],
        ];
    }
}
