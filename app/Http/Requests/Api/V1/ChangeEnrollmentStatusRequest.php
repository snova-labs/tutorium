<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\EnrollmentStatus;
use App\Support\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'status_id' => ['required', 'integer', TenantRule::exists('enrollment_statuses')],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $status = EnrollmentStatus::query()->find($this->integer('status_id'));

                if ($status === null) {
                    return;
                }

                if ($status->code === EnrollmentStatus::TRANSFERRED) {
                    $validator->errors()->add('status_id', 'To move a learner to another batch, use transfer: it enrolls them there too.');

                    return;
                }

                // A reason is required for anything that ends an enrollment, because "why did this
                // learner leave" is the question the record is read for a year later.
                if ($status->is_terminal && trim((string) $this->input('reason')) === '') {
                    $validator->errors()->add('reason', 'Say why: this ends the enrollment, and the reason stays on the record.');
                }
            },
        ];
    }
}
