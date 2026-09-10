<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class AttributeValueResource extends Resource
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
            'value'                => request()->routeIs('attribute-values.index') ? $this->getTranslation('value', app()->getLocale()) : [
                'ar' => $this->getTranslation('value', 'ar'),
                'en' => $this->getTranslation('value', 'en'),
            ],
            'slug'                 => $this->slug,
            $this->mergeWhen(request()->routeIs('attribute-values.show'), [
                'attribute' => new AttributeResource($this->whenLoaded('attribute')),
            ]),
        ];
    }
}