<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\User\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id"                => $this->id,
            "rating"            => $this->rating,
            "comment"           => $this->comment,
            "user"              => UserResource::make($this->whenLoaded('user')),
            "images"            => $this->getmedia('reviews') ? $this->getmedia('reviews')->map(function ($media) {
                return $media->getUrl();
            }) : [],
            "is_approved"        => (bool)$this->is_approved,
        ];
    }
}