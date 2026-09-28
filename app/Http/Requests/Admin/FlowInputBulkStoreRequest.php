<?php

namespace App\Http\Requests\Admin;

use App\Models\OrderFlow\FlowInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk Flow Input creation contract: ONE request, ONE flow, MANY inputs.
 *
 * Body shape is ALWAYS {inputs: [...]} — never a root-level array and
 * never the legacy single-input object. One item = one definition;
 * array position only matters for automatic sort_order allocation.
 */
class FlowInputBulkStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inputs' => ['required', 'array', 'min:1', 'max:100'],
            'inputs.*.key' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]{1,49}$/'],
            'inputs.*.label' => ['required', 'array'],
            'inputs.*.label.en' => ['required', 'string', 'max:100'],
            'inputs.*.label.ar' => ['nullable', 'string', 'max:100'],
            'inputs.*.placeholder' => ['nullable', 'array'],
            'inputs.*.placeholder.en' => ['nullable', 'string', 'max:150'],
            'inputs.*.placeholder.ar' => ['nullable', 'string', 'max:150'],
            'inputs.*.help_text' => ['nullable', 'array'],
            'inputs.*.help_text.en' => ['nullable', 'string', 'max:500'],
            'inputs.*.help_text.ar' => ['nullable', 'string', 'max:500'],
            'inputs.*.type' => ['required', 'string', Rule::in(FlowInput::TYPES)],
            'inputs.*.source' => ['nullable', 'string', Rule::in(FlowInput::SOURCES)],
            'inputs.*.required' => ['sometimes', 'required', 'boolean'],
            'inputs.*.required_at' => ['nullable', 'string', 'max:60'],
            'inputs.*.sort_order' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'inputs.*.validation' => ['nullable', 'array'],
            'inputs.*.validation.options' => ['nullable', 'array', 'max:200'],
            'inputs.*.validation.min' => ['nullable', 'numeric'],
            'inputs.*.validation.max' => ['nullable', 'numeric'],
            'inputs.*.validation.pattern' => ['nullable', 'string', 'max:255'],
            'inputs.*.is_active' => ['sometimes', 'boolean'],
        ];
    }
}
