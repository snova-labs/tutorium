<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Enrollment;
use App\Support\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Enrollment::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'learner_id' => ['required', 'integer', TenantRule::exists('learners')],
            'batch_id' => ['required', 'integer', TenantRule::exists('batches')],
            'enrolled_on' => ['nullable', 'date_format:Y-m-d'],
            'status_id' => ['nullable', 'integer', TenantRule::exists('enrollment_statuses')],
        ];
    }
}
