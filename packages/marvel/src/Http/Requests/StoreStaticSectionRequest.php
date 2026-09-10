<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Marvel\Enums\StaticSectionType;

class StoreStaticSectionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $type = $this->input('type');

        $rules = [
            'type' => 'sometimes|nullable|string|in:' . implode(',', StaticSectionType::getValues()),
            'title' => 'required|array',
            'title.en' => 'required|string|max:255',
            'title.*' => 'nullable|string|max:255',
            'content' => 'nullable|array',
            'content.en' => 'nullable|array',
            'content.ar' => 'nullable|array',
            'config' => 'nullable|array',
            'is_active' => 'nullable|in:0,1,true,false,TRUE,FALSE',
            'remove_media' => 'prohibited',
        ];

        // Per-type media validation (file field is `media` for all media types)
        // When type is absent we default to text (no media) to preserve legacy tests.
        if (in_array($type, StaticSectionType::imageTypes(), true)) {
            $rules['media'] = 'required|file|mimetypes:image/jpeg,image/png,image/jpg,image/webp,image/gif|max:5120';
        } elseif ($type === StaticSectionType::VIDEO) {
            $rules['media'] = 'required|file|mimetypes:video/mp4,video/webm,video/ogg,video/quicktime,video/x-msvideo|max:20480';
        } else {
            // text and fallback (including null/legacy) — media must not be supplied
            $rules['media'] = 'nullable|prohibited';
        }

        return $rules;
    }

    /**
     * The top level of `content` must be an associative locale-keyed object.
     *
     * A top-level JSON list would trigger Spatie's single-locale translation
     * branch, so it is rejected here before it ever reaches the model.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $content = $this->input('content');
            $type = $this->input('type') ?? StaticSectionType::TEXT;

            if (is_array($content) && array_is_list($content)) {
                $validator->errors()->add('content', __(STATIC_SECTION_CONTENT_INVALID));
            }

            // Text sections require content (default when type null = text legacy)
            if ($type === StaticSectionType::TEXT) {
                if (empty($content) || !is_array($content)) {
                    $validator->errors()->add('content', __('validation.required', ['attribute' => 'content']));
                }
            }
        });
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}