<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Hero;

class StoreHeroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $description = $this->input('description');

        if (is_string($description)) {
            $decoded = json_decode($description, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->merge(['description' => $decoded]);
            } else {
                $this->merge(['description' => ['en' => $description, 'ar' => $description]]);
            }
        }

        if ($this->has('is_active') && $this->input('is_active') !== null) {
            $val = $this->input('is_active');
            if (is_string($val)) {
                $lower = strtolower($val);
                if (in_array($lower, ['true', '1', 'on', 'yes'], true)) {
                    $this->merge(['is_active' => true]);
                } elseif (in_array($lower, ['false', '0', 'off', 'no'], true)) {
                    $this->merge(['is_active' => false]);
                }
            }
        }
    }

    public function rules(): array
    {
        $type = $this->input('type');

        $rules = [
            'type' => 'required|string|in:' . implode(',', [Hero::TYPE_IMAGE, Hero::TYPE_VIDEO]),
            'description' => 'nullable|array',
            'description.en' => 'nullable|string|max:1000',
            'description.ar' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
        ];

        if ($type === Hero::TYPE_VIDEO) {
            $rules['media'] = 'required|file|mimetypes:video/mp4,video/webm,video/ogg,video/quicktime,video/x-msvideo|max:20480';
        } else {
            $rules['media'] = 'required|file|mimetypes:image/jpeg,image/png,image/jpg,image/webp,image/gif|max:5120';
        }

        return $rules;
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}

