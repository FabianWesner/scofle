<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreImageUploadRequest extends FormRequest
{
    private const CHOOSE_IMAGES_MESSAGE = 'Choose one or more PNG or JPEG images.';

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
                'max:8000',
            ],
            'nonce' => ['required', 'string', 'size:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.required' => self::CHOOSE_IMAGES_MESSAGE,
            'images.array' => self::CHOOSE_IMAGES_MESSAGE,
            'images.max' => 'Upload at most '.config('conversion.max_batch_uploads').' images at once.',
            'images.*.required' => self::CHOOSE_IMAGES_MESSAGE,
            'images.*.max' => 'One of the images is too large. Upload PNG or JPEG images up to 8 MB.',
            'nonce.required' => 'This upload form expired. Refresh and try again.',
        ];
    }
}
