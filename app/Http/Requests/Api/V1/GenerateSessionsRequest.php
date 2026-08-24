<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class GenerateSessionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('schedule', $this->route('batch'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Either a named period, or an explicit range. A period is what the interface sends.
            'period' => ['nullable', 'string', 'max:60', 'required_without_all:from,to'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ];
    }
}
