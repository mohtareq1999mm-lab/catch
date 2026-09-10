<?php

declare(strict_types=1);

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CmsPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id') ?? $this->route('cms_page');

        return [
            // Path field - required for Puck routing (e.g., "/", "/about")
            'path' => [
                'required',
                'string',
                'max:191',
                Rule::unique('cms_pages', 'path')->ignore($id),
            ],

            // Slug - now optional (for backward compatibility)
            'slug' => [
                'nullable',
                'string',
                'max:191',
                Rule::unique('cms_pages', 'slug')->ignore($id),
            ],

            'title' => ['required', 'string', 'max:191'],

            // Legacy content format (array of components)
            'content' => ['nullable', 'array'],
            'content.*.type' => ['sometimes', 'string'],
            'content.*.props' => ['nullable', 'array'],
            'content.*.order' => ['nullable', 'integer'],

            // Puck data format (root, content, zones)
            'data' => ['nullable', 'array'],
            'data.root' => ['nullable', 'array'],
            'data.root.props' => ['nullable', 'array'],
            'data.content' => ['nullable', 'array'],
            'data.content.*.type' => ['sometimes', 'string'],
            'data.content.*.props' => ['nullable', 'array'],
            'data.zones' => ['nullable', 'array'],

            // Meta information
            'meta' => ['nullable', 'array'],
        ];
    }

    public function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}


