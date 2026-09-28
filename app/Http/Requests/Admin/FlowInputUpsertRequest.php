<?php

namespace App\Http\Requests\Admin;

use App\Models\OrderFlow\FlowInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FlowInputUpsertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => ['sometimes', 'required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]{1,49}$/'],
            'label' => ['sometimes', 'required', 'array'],
            'label.en' => ['sometimes', 'required', 'string', 'max:100'],
            'label.ar' => ['nullable', 'string', 'max:100'],
            'placeholder' => ['nullable', 'array'],
            'placeholder.en' => ['nullable', 'string', 'max:150'],
            'placeholder.ar' => ['nullable', 'string', 'max:150'],
            'help_text' => ['nullable', 'array'],
            'help_text.en' => ['nullable', 'string', 'max:500'],
            'help_text.ar' => ['nullable', 'string', 'max:500'],
            'type' => ['sometimes', 'required', 'string', Rule::in(FlowInput::TYPES)],
            'source' => ['nullable', 'string', Rule::in(FlowInput::SOURCES)],
            'required' => ['sometimes', 'required', 'boolean'],
            'required_at' => ['sometimes', 'required', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'validation' => ['nullable', 'array'],
            'validation.options' => ['nullable', 'array', 'max:200'],
            'validation.min' => ['nullable', 'numeric'],
            'validation.max' => ['nullable', 'numeric'],
            'validation.pattern' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
