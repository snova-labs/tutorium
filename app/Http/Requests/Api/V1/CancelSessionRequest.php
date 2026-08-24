<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class CancelSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cancel', $this->route('session'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Required, because "why was this cancelled" is the first question anyone asks of the
            // record six weeks later.
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
