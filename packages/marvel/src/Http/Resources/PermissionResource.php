<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        $key = 'permissions.' . $this->name;
        $translation = __($key);

        return [
            "id" => $this->id,
            "label" => $translation !== $key ? $translation : $this->name,
        ];
    }
}
