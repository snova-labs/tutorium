<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\BatchStatus;
use App\Enums\DeliveryMode;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class UpdateBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('batch'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'code' => [
                'sometimes', 'string', 'max:32', 'alpha_dash',
                Rule::unique('batches', 'code')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($this->route('batch')?->getKey())
                    ->whereNull('deleted_at'),
            ],
            'timezone' => ['sometimes', 'timezone:all'],
            'starts_on' => ['sometimes', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'capacity' => ['nullable', 'integer', 'between:1,500'],
            'delivery_mode' => ['sometimes', new Enum(DeliveryMode::class)],
            'status' => ['sometimes', new Enum(BatchStatus::class)],
        ];
    }
}
