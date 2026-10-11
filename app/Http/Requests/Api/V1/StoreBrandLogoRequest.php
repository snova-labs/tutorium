<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

final class StoreBrandLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('brand'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Raster formats only. An SVG is a document that may carry script, and this file is
            // served back to browsers, so accepting one would be a stored cross-site script.
            'logo' => [
                'required',
                File::image()
                    ->types(['png', 'jpg', 'jpeg', 'webp'])
                    ->max(config('branding.logo.max_kb').'kb')
                    ->dimensions(Rule::dimensions()
                        ->maxWidth((int) config('branding.logo.max_pixels'))
                        ->maxHeight((int) config('branding.logo.max_pixels'))),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $kb = (int) config('branding.logo.max_kb');
        $pixels = (int) config('branding.logo.max_pixels');

        return [
            'logo.image' => 'Use a PNG, JPG or WebP image.',
            'logo.extensions' => 'Use a PNG, JPG or WebP image.',
            'logo.mimes' => 'Use a PNG, JPG or WebP image.',
            'logo.mimetypes' => 'Use a PNG, JPG or WebP image.',
            'logo.max' => sprintf('Keep the file under %dKB. This one is larger.', $kb),
            'logo.dimensions' => sprintf('Keep the image within %d by %d pixels.', $pixels, $pixels),
        ];
    }
}
