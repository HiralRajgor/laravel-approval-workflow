<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'event'      => $this->event,
            'meta'       => $this->meta,
            'user'       => new UserResource($this->whenLoaded('user')),
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
