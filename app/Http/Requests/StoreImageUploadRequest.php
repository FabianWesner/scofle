<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreImageUploadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'max:'.(int) ceil(config('upload.max_bytes') / 1024),
            ],
            'nonce' => ['required', 'string', 'size:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'Choose a PNG or JPEG image.',
            'image.max' => 'Image is too large. Upload a PNG or JPEG up to 10 MB.',
            'image.mimes' => 'Only PNG and JPEG images are accepted.',
            'image.mimetypes' => 'Only PNG and JPEG images are accepted.',
            'nonce.required' => 'This upload form expired. Refresh and try again.',
        ];
    }
}
