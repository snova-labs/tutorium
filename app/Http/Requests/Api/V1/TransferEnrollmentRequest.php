<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

final class TransferEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transfer', $this->route('enrollment'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'batch_id' => ['required', 'integer', TenantRule::exists('batches')],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
