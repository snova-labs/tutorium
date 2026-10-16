<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding a term to one of the academy's lists, or changing one.
 *
 * No `code` field: the service makes it from the name and never changes it afterwards, so that an
 * import written against TRIAL_LESSON keeps working when somebody renames it to "Taster class".
 */
final class StoreVocabularyTermRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $adding = $this->isMethod('POST');

        return [
            'name' => [$adding ? 'required' : 'sometimes', 'string', 'max:60'],
            // Nullable rather than absent-able: sending an empty colour is how you clear one.
            'color' => ['sometimes', 'nullable', 'string', 'max:9'],
            'flag' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Give it a name.',
            'name.max' => 'A name is 60 characters at most.',
        ];
    }
}
