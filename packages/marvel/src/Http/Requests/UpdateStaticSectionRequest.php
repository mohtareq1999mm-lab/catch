<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Marvel\Enums\StaticSectionType;

class UpdateStaticSectionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $type = $this->input('type');

        $rules = [
            'type' => 'sometimes|string|in:' . implode(',', StaticSectionType::getValues()),
            'title' => 'sometimes|array',
            'title.en' => 'sometimes|string|max:255',
            'title.*' => 'sometimes|nullable|string|max:255',
            'content' => 'sometimes|array',
            'content.en' => 'nullable|array',
            'content.ar' => 'nullable|array',
            'config' => 'sometimes|nullable|array',
            'is_active' => 'sometimes|nullable|in:0,1,true,false,TRUE,FALSE',
            'remove_media' => 'sometimes|nullable|in:0,1,true,false,TRUE,FALSE',
        ];

        // Media is optional on update; if supplied, validate per resolved type
        // Resolved type is the incoming type if present, otherwise existing model type is checked in withValidator
        if ($type !== null) {
            if (in_array($type, StaticSectionType::imageTypes(), true)) {
                $rules['media'] = 'sometimes|nullable|file|mimetypes:image/jpeg,image/png,image/jpg,image/webp,image/gif|max:5120';
            } elseif ($type === StaticSectionType::VIDEO) {
                $rules['media'] = 'sometimes|nullable|file|mimetypes:video/mp4,video/webm,video/ogg,video/quicktime,video/x-msvideo|max:20480';
            } elseif ($type === StaticSectionType::TEXT) {
                $rules['media'] = 'sometimes|nullable|prohibited';
            }
        } else {
            // No type in payload — allow media file with loose constraints; strict check in withValidator
            $rules['media'] = 'sometimes|nullable|file|max:20480';
        }

        return $rules;
    }

    /**
     * When `content` is supplied it must be an associative locale-keyed object.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $content = $this->input('content');
            $type = $this->input('type');

            if ($content !== null && is_array($content) && array_is_list($content)) {
                $validator->errors()->add('content', __(STATIC_SECTION_CONTENT_INVALID));
            }

            // If type is being changed to text, content should be present (either in payload or already exists)
            // We cannot check existing model here; service will handle, but prevent explicit empty content for text
            if ($type === StaticSectionType::TEXT && $content !== null && empty($content)) {
                $validator->errors()->add('content', __('validation.required', ['attribute' => 'content']));
            }

            // If type absent but media supplied, we need to defer strict mime check to mime validation above;
            // However if type is text and media present -> prohibited already handled.

            // Validate media mime against resolved type when type not in payload: we check file mime loosely.
            // Strict per-type mime for updates without type change is enforced in service via collection type.
        });
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}