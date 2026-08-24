<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\Weekday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTimetableSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('batch'));
    }

    /** Accepts either "monday" or 1, because both read naturally depending on the caller. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('weekday'))) {
            $this->merge(['weekday' => Weekday::fromName($this->string('weekday')->toString())->value]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'session_type_id' => ['required', 'integer', Rule::exists('session_types', 'id')],
            'weekday' => ['required', 'integer', 'between:1,7'],
            // Wall-clock time in the batch timezone. The instant is derived per occurrence.
            'start_time_local' => ['required', 'date_format:H:i'],
            'duration_min' => ['required', 'integer', 'between:5,600'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'start_time_local.date_format' => 'Use a 24-hour time such as 09:00 or 17:30.',
            'weekday.between' => 'Monday is 1 and Sunday is 7.',
        ];
    }
}
