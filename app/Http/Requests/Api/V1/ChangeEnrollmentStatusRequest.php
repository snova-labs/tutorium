<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeEnrollmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('enrollment'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status_id' => ['required', 'integer', Rule::exists('enrollment_statuses', 'id')],
            // A reason is required for anything that ends an enrollment, because "why did this
            // learner leave" is the question the record is read for a year later.
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
