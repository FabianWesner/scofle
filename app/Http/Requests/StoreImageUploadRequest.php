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
            'images' => [
                'required',
                'array',
                'min:1',
                'max:'.(int) config('conversion.max_batch_uploads'),
            ],
            'images.*' => [
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
            'images.required' => 'Choose one or more PNG or JPEG images.',
            'images.array' => 'Choose one or more PNG or JPEG images.',
            'images.max' => 'Upload at most '.config('conversion.max_batch_uploads').' images at once.',
            'images.*.required' => 'Choose one or more PNG or JPEG images.',
            'images.*.max' => 'One of the images is too large. Upload PNG or JPEG images up to 10 MB.',
            'nonce.required' => 'This upload form expired. Refresh and try again.',
        ];
    }
}
