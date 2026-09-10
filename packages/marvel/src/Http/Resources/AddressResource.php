<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class AddressResource extends Resource
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
            "id"=> $this->id,
            "title"=> $this->title,
            "address"=> $this->address,
            "location"=> $this->location ?? [],
            "customer_id"=> $this->customer_id,
            "created_at"=> $this->created_at->toIsoString(),
        ];
    }
}