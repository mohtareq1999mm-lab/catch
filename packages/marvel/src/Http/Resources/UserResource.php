<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class UserResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'is_active' => $this->is_active,
            'image' => $this->getFirstMediaUrl('user-image') ?: null,
            'type' => $this->type,
            'phone_number' => $this->phone_number,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            'permissions' => PermissionResource::collection($this->getPermissionsViaRoles()),
            'address' => $this->whenLoaded('address'),
        ];
    }
}
