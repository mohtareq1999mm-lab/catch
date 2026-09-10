<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateContentPageRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'title' => 'sometimes|array',
            'title.*' => ['sometimes', 'string', 'max:30', UniqueTranslationRule::for('content_pages', 'title')->ignore($this->route('content_page'))],
            'is_active' => 'sometimes|in:0,1',
        ];
    }
}