<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class AttributeResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'                   => $this->id,
            'name'                 => request()->routeIs('attributes.index') ? $this->getTranslation('name', app()->getLocale()) : [
                'ar' => $this->getTranslation('name', 'ar'),
                'en' => $this->getTranslation('name', 'en'),
            ],
            'slug'                 => $this->slug,
            'values'               => $this->whenLoaded('values', AttributeValueResource::collection($this->values))

        ];
    }
}