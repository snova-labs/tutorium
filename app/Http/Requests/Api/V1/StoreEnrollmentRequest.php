<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'learner_id' => ['required', 'integer', Rule::exists('learners', 'id')],
            'batch_id' => ['required', 'integer', Rule::exists('batches', 'id')],
            'enrolled_on' => ['nullable', 'date_format:Y-m-d'],
            'status_id' => ['nullable', 'integer', Rule::exists('enrollment_statuses', 'id')],
        ];
    }
}
