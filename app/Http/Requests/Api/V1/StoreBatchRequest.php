<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\BatchStatus;
use App\Enums\DeliveryMode;
use App\Models\Batch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class StoreBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Batch::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
            'name' => ['required', 'string', 'max:160'],
            'code' => [
                'required', 'string', 'max:32', 'alpha_dash',
                Rule::unique('batches', 'code')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->whereNull('deleted_at'),
            ],
            // Left out, the batch follows its branch. Given, it must be a real IANA name —
            // a typo here mis-schedules every session under this batch.
            'timezone' => ['nullable', 'timezone:all'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'capacity' => ['nullable', 'integer', 'between:1,500'],
            'delivery_mode' => ['nullable', new Enum(DeliveryMode::class)],
            'status' => ['nullable', new Enum(BatchStatus::class)],
        ];
    }
}
