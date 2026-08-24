<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Learner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLearnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Learner::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // One name field, not two. See the note on the learners table.
            'legal_name' => ['required', 'string', 'max:190'],
            'preferred_name' => ['nullable', 'string', 'max:120'],
            'sort_name' => ['nullable', 'string', 'max:190'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'size:2'],
            'home_timezone' => ['nullable', 'timezone:all'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'status_id' => ['nullable', 'integer', Rule::exists('learner_statuses', 'id')],
            'custom' => ['nullable', 'array'],

            // Optional guardian, created or matched in the same request — the front desk enters a
            // child and a parent together, so the API should accept them together.
            'guardian' => ['nullable', 'array'],
            'guardian.name' => ['required_with:guardian', 'string', 'max:190'],
            'guardian.email' => ['nullable', 'email', 'max:190'],
            'guardian.phone' => ['nullable', 'string', 'max:32'],
            'guardian.relation_type_id' => ['nullable', 'integer', Rule::exists('relation_types', 'id')],
            'guardian.receives_reports' => ['boolean'],

            // Optional immediate enrollment.
            'batch_id' => ['nullable', 'integer', Rule::exists('batches', 'id')],

            // Set after reviewing the duplicate warning. Never a default.
            'force' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'legal_name.required' => 'A name is required.',
            'date_of_birth.before' => 'A date of birth cannot be in the future.',
            'home_timezone.timezone' => 'Use a timezone name such as America/Toronto.',
        ];
    }
}
