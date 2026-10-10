<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

final class SaveAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('record', $this->route('session'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'marks' => ['required', 'array', 'min:1'],
            'marks.*.enrollment_id' => ['required', 'integer', TenantRule::exists('enrollments')],
            'marks.*.status_id' => ['required', 'integer', TenantRule::exists('attendance_statuses')],
            'marks.*.minutes_late' => ['nullable', 'integer', 'between:0,600'],
            'marks.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'marks.required' => 'Send at least one mark.',
            'marks.*.status_id.exists' => 'That attendance status does not exist in this account.',
        ];
    }
}
