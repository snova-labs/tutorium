<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Holiday;
use Illuminate\Foundation\Http\FormRequest;

final class StoreClosureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Holiday::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:120'],
            // A closure that does not block sessions is a note on the calendar: the office is
            // shut, teaching goes ahead.
            'blocks_sessions' => ['boolean'],
            // Sessions already generated for that day are only cancelled when asked for.
            'cancel_sessions' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date.date_format' => 'Use a date such as 2026-10-20.',
        ];
    }
}
